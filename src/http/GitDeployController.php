<?php

namespace Orphans\GitDeploy\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Artisan;
use Log;
use Event;

use Orphans\GitDeploy\Events\GitDeployed;

class GitDeployController extends Controller
{
    public function gitHook(Request $request)
    {

        // create a log channel
        $log = new Logger('gitdeploy');
        $log->pushHandler(new StreamHandler(storage_path('logs/gitdeploy.log'), Logger::DEBUG));

        $git_path = !empty(config('gitdeploy.git_path')) ? config('gitdeploy.git_path') : 'git';
        $git_remote = !empty(config('gitdeploy.remote')) ? config('gitdeploy.remote') : 'origin';

        // Limit to known servers
        if (!empty(config('gitdeploy.allowed_sources'))) {
            $remote_ip = $this->formatIPAddress($_SERVER['REMOTE_ADDR']);
            $allowed_sources = array_map([$this, 'formatIPAddress'], config('gitdeploy.allowed_sources'));

            if (!in_array($remote_ip, $allowed_sources)) {
                $log->addError('Request must come from an approved IP');
                return Response::json([
                            'success' => false,
                            'message' => 'Request must come from an approved IP',
                        ], 401);
            }
        }

        // Collect the posted data
        $postdata = json_decode($request->getContent(), true);
        if (empty($postdata)) {
            $log->addError('Web hook data does not look valid');
            return Response::json([
                        'success' => false,
                        'message' => 'Web hook data does not look valid',
                ], 400);
        }

        // Get repository name this webhook is for if push request
        if (isset($postdata['repository']['name'])) {
            $pushed_repo_name = trim($postdata['repository']['name']);
            // Get repository name this webhook is for if pull request
        } elseif (isset($postdata['base']['repository']['name'])) {
            $pushed_repo_name = trim($postdata['base']['repository']['name']);
            // Get repository name fails
        } else {
            $log->addWarning('Could not determine repository name for action');
            return Response::json([
                'success' => false,
                'message' => 'Could not determine repository name for action',
            ], 422);
        }

        // Get branch this webhook is for if push event
        if (isset($postdata['ref'])) {
            $pushed_branch = explode('/', $postdata['ref']);
            $pushed_branch = trim($pushed_branch[2]);
            // Get branch this webhook is for if pull request
        } elseif (isset($postdata['base']['ref'])) {
            $pushed_branch = explode('/', $postdata['base']['ref']);
            $pushed_branch = trim($pushed_branch[2]);
            // Get branch fails
        } else {
            $log->addWarning('Could not determine refs for action');
            return Response::json([
                'success' => false,
                'message' => 'Could not determine refs for action',
            ], 422);
        }

        $config_base='gitdeploy.projects.self.';

        foreach(config('gitdeploy.projects') as $key => $project){
            if($project['repo_name'] == $pushed_repo_name && $project['branch'] == $pushed_branch){
                $config_base='gitdeploy.projects.' . $key . '.';
                break;
            }
        }

        // Check the config's directory
        $repo_path = config($config_base . 'repo_path');
        if (!empty($repo_path) && !file_exists($repo_path.'/.git/config')) {
            $log->addError('Invalid repo path in config');
            return Response::json([
                'success' => false,
                'message' => 'Invalid repo path in config',
            ], 500);
        }

        // Try to determine Laravel's directory going up paths until we find a .env
        if (empty($repo_path)) {
            $checked[] = $repo_path;
            $repo_path = __DIR__;
            do {
                $repo_path = dirname($repo_path);
            } while ($repo_path !== '/' && !file_exists($repo_path.'/.env'));
        }

        // This is not necessarily the repo's root so go up more paths if necessary
        if ($repo_path !== '/') {
            while ($repo_path !== '/' && !file_exists($repo_path.'/.git/config')) {
                $repo_path = dirname($repo_path);
            }
        }

        // So, do we have something valid?
        if ($repo_path === '/' || !file_exists($repo_path.'/.git/config')) {
            $log->addError('Could not determine the repo path');
            return Response::json([
                'success' => false,
                'message' => 'Could not determine the repo path',
            ], 500);
        }
        // If last folder of the repo_path is named "current" assuming zero downtime deploy
        // Need to copy current directory to new hashed directory then reset repo_path
        if(basename($repo_path) === 'current'){
            $new_repo_path = dirname($repo_path) . '/' . $postdata['after'];
            $cmd = 'cp -r '
                . escapeshellarg($repo_path)
                . ' '
                . escapeshellarg($new_repo_path);
            exec($cmd);
            $repo_path = $new_repo_path;
        }

        // Determine the repository name
        $repo_name = config($config_base . 'repo_name');
        if (empty($repo_name)) {
            // Get current repository name
            $cmd = 'basename -s .git $('
                . escapeshellcmd($git_path)
                . ' --git-dir=' . escapeshellarg($repo_path . '/.git')
                . ' --work-tree=' . escapeshellarg($repo_path)
                . ' remote get-url '
                . escapeshellarg(config($config_base . 'remote'))
                . ' )';
            $repo_name = trim(exec($cmd));
        }

        // Get current branch this repository is on
        $branch = config($config_base . 'branch');
        if (empty($branch)){
            $cmd = escapeshellcmd($git_path)
                . ' --git-dir=' . escapeshellarg($repo_path . '/.git')
                . ' --work-tree=' . escapeshellarg($repo_path)
                . ' rev-parse --abbrev-ref HEAD';
            $branch = trim(exec($cmd)); //Alternatively shell_exec
        }

        // If the repo name doesn't match this repo, then no need to do a git pull
        if ($repo_name !== $pushed_repo_name) {
            $log->addWarning('Pushed name does not match current repo name');
            return Response::json([
                'success' => false,
                'message' => 'Pushed name does not match current repo name',
            ], 422);
        }

        // Check signatures
        if (!empty(config($config_base . 'secret'))) {
            $header = config($config_base . 'secret_header');
            $header_data = $request->header($header);

            /**
             * Check for valid header
             */
            if (!$header_data) {
                $log->addError('Could not find header with name ' . $header);
                return Response::json([
                    'success' => false,
                    'message' => 'Could not find header with name ' . $header,
                ], 401);
            }

            /**
             * Sanity check for key
             */
            if (empty(config($config_base . 'secret_key'))) {
                $log->addError('Secret was set to true but no secret_key specified in config');
                return Response::json([
                    'success' => false,
                    'message' => 'Secret was set to true but no secret_key specified in config',
                ], 500);
            }

            /**
             * Check plain secrets (Gitlab)
             */
            if (config($config_base . 'secret_type') == 'plain') {
                if ($header_data !== config($config_base . 'secret_key')) {
                    $log->addError('Secret did not match');
                    return Response::json([
                        'success' => false,
                        'message' => 'Secret did not match',
                    ], 401);
                }
            }

            /**
             * Check hmac secrets (Github)
             */
            elseif (config($config_base . 'secret_type') == 'mac') {
                // @TODO figure out how this should work, but for now it fixes the function call
                if (!hash_equals('sha1=abcdefghijklmnopqrst','sha1=' . hash_hmac('sha1', $request->getContent(), config($config_base . 'secret')))) {
                    $log->addError('Secret did not match');
                    return Response::json([
                        'success' => false,
                        'message' => 'Secret did not match',
                    ], 401);
                }
            }

            /**
             * Catch all for anything odd in config
             */
            else {
                $log->addError('Unsupported secret type');
                return Response::json([
                    'success' => false,
                    'message' => 'Unsupported secret type',
                ], 422);
            }

            // If we get this far then the secret matched, lets go ahead!
        }

        // If the refs don't matchthis branch, then no need to do a git pull
        if ($branch !== $pushed_branch) {
            $log->addWarning('Pushed refs do not match current branch');
            return Response::json([
                'success' => false,
                'message' => 'Pushed refs do not match current branch',
            ], 422);
        }

        // At this point we're happy everything is OK to pull, lets put Laravel into Maintenance mode.
        if (!empty(config($config_base . 'maintenance_mode'))) {
            Log::info('Gitdeploy: putting site into maintenance mode');
            Artisan::call('down');
        }
        //Get PATH
        $path=array();
        exec('echo $PATH', $path);
        $path=$path[0];

        //Log PATH before changing it
        $log->info('Gitdeploy: PATH before: ' . $path);

        //Add to PATH so node can be found
        putenv('PATH=' . $path . ':/usr/local/bin:/usr/bin');

        //Check PATH after setting and log it
        $path=array();
        exec('echo $PATH', $path);
        $path=$path[0];
        $log->info('Gitdeploy: PATH after: ' . $path);

        // git pull
        Log::info('Gitdeploy: Pulling latest code on to server');

        $output = array();
        $returnCode = '';

        $cmd = escapeshellcmd($git_path)
                . ' --git-dir='
                . escapeshellarg($repo_path . '/.git')
                . ' --work-tree=' . escapeshellarg($repo_path)
                . ' pull ' . escapeshellarg($git_remote)
                . ' '
                . escapeshellarg($branch);

        exec($cmd, $output, $returnCode);

        $server_response = [
            'cmd' => $cmd,
            'user' => shell_exec('whoami'),
            'response' => $output,
            'return_code' => $returnCode,
        ];
        $log->info('Gitdeploy: ' . $cmd . 'finished with code: ' . $returnCode);
        $log->info('Gitdeploy: ' . $cmd . 'output: ' . print_r($output, true));

        //Lets see if we have commands to run and run them
        if (!empty(config($config_base . 'commands'))) {
            $commands = config($config_base . 'commands');
            $command_results = array();
            foreach ($commands as $command) {
                $output = array();
                $returnCode = '';
                $cmd = escapeshellcmd('cd')
                        . ' '
                        . escapeshellarg($repo_path)
                        . ' ; '
                        . escapeshellcmd($command)
                        . ' 2>&1';
                $log->info('Gitdeploy: Running post pull command: '.$cmd);
                exec($cmd, $output, $returnCode);
                array_push($command_results, [
                    'cmd' => $cmd,
                    'output' => $output,
                    'return_code' => $returnCode,
                ]);
                $log->info('Gitdeploy: ' . $cmd . 'finished with code: ' . $returnCode);
                $log->info('Gitdeploy: ' . $cmd . 'output: ' . print_r($output, true));
            }
        }

        // Put site back up and end maintenance mode
        if (!empty(config($config_base . 'maintenance_mode'))) {
            Artisan::call('up');
            Log::info('Gitdeploy: taking site out of maintenance mode');
        }

        // Fire Event that git were deployed
        if (!empty(config($config_base . 'fire_event'))) {
            event(new GitDeployed($postdata['commits']));
            Log::debug('Gitdeploy: Event GitDeployed fired');
        }

        if (!empty(config($config_base . 'email_recipients'))) {

            // Humanise the commit log
            foreach ($postdata['commits'] as $commit_key => $commit) {

                // Split message into subject + description (Assumes Git's recommended standard where first line is the main summary)
                $subject = strtok($commit['message'], "\n");
                $description = '';

                // Beautify date
                $date = new \DateTime($commit['timestamp']);
                $date_str = $date->format('d/m/Y, g:ia');

                $postdata['commits'][$commit_key]['human_id'] = substr($commit['id'], 0, 9);
                $postdata['commits'][$commit_key]['human_subject'] = $subject;
                $postdata['commits'][$commit_key]['human_description'] = $description;
                $postdata['commits'][$commit_key]['human_date'] = $date_str;
            }

            // Standardise formats for Gitlab / Github payload differences
            if (isset($postdata['pusher']) && !empty($postdata['pusher'])) {
                $postdata['user_name'] = $postdata['pusher']['name'];
                $postdata['user_email'] = $postdata['pusher']['email'];
            }
            
            // Use package's own sender or the project default?
            $addressdata['sender_name'] = config('mail.from.name');
            $addressdata['sender_address'] = config('mail.from.address');
            if (config($config_base . 'email_sender.address') !== null) {
                $addressdata['sender_name'] = config($config_base . 'email_sender.name');
                $addressdata['sender_address'] = config($config_base . 'email_sender.address');
            }

            // Recipients
            $addressdata['recipients'] = config($config_base . 'email_recipients');

            // Template
            $emailTemplate = config($config_base . 'email_template', 'gitdeploy::email');

            // Todo: Put Mail send into queue to improve performance
            \Mail::send($emailTemplate, [ 'server' => $server_response, 'git' => $postdata, 'command_results' => $command_results ], function ($message) use ($postdata, $addressdata) {
                $message->from($addressdata['sender_address'], $addressdata['sender_name']);
                foreach ($addressdata['recipients'] as $recipient) {
                    $message->to($recipient['address'], $recipient['name']);
                }
                $message->subject('Repo: ' . $postdata['repository']['name'] . ' updated');
            });
        }

        return Response::json(true);
    }


    /**
     * Make sure we're comparing like for like IP address formats.
     * Since IPv6 can be supplied in short hand or long hand formats.
     *
     * e.g. ::1 is equalvent to 0000:0000:0000:0000:0000:0000:0000:0001
     *
     * @param  string $ip   Input IP address to be formatted
     * @return string   Formatted IP address
     */
    private function formatIPAddress(string $ip)
    {
        return inet_ntop(inet_pton($ip));
    }
}
