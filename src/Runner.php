<?php

namespace CLU;

use JiraRestApi\JiraException;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;

class Runner {

	/**
	 * Git repository URL.
	 *
	 * @var string
	 */
	private $repo_url;

        private $target_dir;

	/**
	 * Instantiate the runner.
	 *
	 * @param string $repo_url
	 * @param \stdClass $provider
	 */
	public function __construct( $repo_url, \stdClass $provider ) {
		$this->repo_url = $repo_url;
		$this->provider = $provider;
	}

	public function start( $target_dir, $opts ) {
		
		$this->target_dir = $target_dir;
		// Clone the repository to a working directory.
		$shorthash = substr( md5( mt_rand() . time() ), 0, 7 );

		// Run all future commands from the context of the target directory.
		chdir( $target_dir );
		Logger::warning( 'Changed into directory: ' . getcwd() );

		// Check if there are any security advisories for any of the
		// versions of any of our dependencies in use right now.
		# $security_message = $this->checkSensiolabsSecurity( 'composer.lock', $is_vulnerable );
		$security_message = $this->checkComposerAudit($is_vulnerable );
		Logger::warning( $security_message );

		// Exit early if user requested security updates only, and no dependencies
		// are vulnerable.
		if ( isset($opts['security-only']) && !$is_vulnerable ) {
			Logger::info('Exiting since --security-only was specified, and there are no security updates available.');
			exit(0);
		}

		// Determine whether there is an existing open PR with Composer updates
		$existing_PR_branch = $this->checkExistingPRBranch( 'branch' );
		echo "existing_PR_branch = " . $existing_PR_branch . "\n";
		Logger::warning( "existing_PR_branch = " . $existing_PR_branch);
        Logger::warning( sprintf( 'start %s #%d', '=', $existing_PR_branch ) );
		if ( $existing_PR_branch ) {
			exec( 'git rev-parse --abbrev-ref HEAD', $initial_branch, $return_code );
			if ( 0 !== $return_code ) {
				Logger::error( 'Failed to fetch initial branch.' );
			}
			$initial_branch = $initial_branch[0];
			Logger::warning( "Inspecting existing branch: $existing_PR_branch" );
			passthru( 'git fetch' );
			passthru( 'git checkout ' . $existing_PR_branch );
			// Check to see if there are any outdated dependencies.
			$this->runComposerInstall();
			$this->runComposerUpdate();
			if ( ! $this->checkComposerLock() ) {
				exit(0);
			}
			// Close the existing PR and delete its branch.
			$this->closeExistingPRBranch( $existing_PR_branch );
			// Check out the initial branch locally and delete the local PR branch.
			passthru( 'git checkout -f ' . $initial_branch );
			$cmd = 'git branch -D ' . escapeshellarg( $existing_PR_branch );
			Logger::warning( $cmd );
			passthru( $cmd, $return_code );
			if ( 0 !== $return_code ) {
				Logger::error( 'Failed to delete local branch.' );
			}
			// Fall through to create the new branch.
		}
        Logger::warning( "Run composer install" );
		// Perform an initial install to sanity check the package.
		$this->runComposerInstall();

		// Run composer update, but capture output for the commit message if needed.
		$update_message = $this->runComposerUpdate();

		// Check whether composer.lock was modifed.
		if ( ! $this->checkComposerLock() ) {
			exit(0);
		}

		$git_name = getenv( 'CLU_GIT_NAME' ) ? : 'composer-lock-update';
		exec( 'git config user.name ' . escapeshellarg( $git_name ), $_, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to set git config name.' );
		}
		$git_email = getenv( 'CLU_GIT_EMAIL' ) ? : 'composer-lock-update@localhost';
		exec( 'git config user.email ' . escapeshellarg( $git_email ), $_, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to set git config email.' );
		}
		Logger::warning( 'Set git config name and email.' );

		$date = date( 'Y-m-d-H-i' );
		// Checkout a dated branch to make the commit
		$branch_name = 'clu-' . $date;
		$cmd = 'git checkout -b ' . escapeshellarg( $branch_name );
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to check out branch.' );
		}

        $message = '';

        $clu_jira_projectid = getenv( 'CLU_JIRA_PROJECTID');
        $clu_jira_assigneeid = getenv( 'CLU_JIRA_ASSIGNEEID');

        if($clu_jira_projectid) {
        	// Check if this is an existing ticket number.
        	if (preg_match('~-[0-9]+~', $clu_jira_projectid)) {
        	    $message .= 'Issue #' . $clu_jira_projectid . "\n";
            }
            else {
        	    $ret = $this->jiraIssueCreate($clu_jira_projectid, $opts = [
                    'assignee' => $clu_jira_assigneeid,
                    'description' => "Automated composer dependency updates for: " . $this->repo_url . "\n\n" . $update_message . $security_message,
                    'summary' => "Update Composer dependencies " . $date]);
        	    $message .= 'Issue #' . $ret->key . "\n\n";
            }
        }

		$message .= <<<EOT
Update Composer dependencies ({$date})

```
{$update_message}{$security_message}
```
EOT;

		$cmd = 'git commit -am ' . escapeshellarg( $message );
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to commit changes.' );
		}

		$cmd = 'git push origin ' . escapeshellarg( $branch_name );
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to push changes to origin.' );
		}

		$cmd = sprintf($this->provider->pr_create, escapeshellarg( $message ));
                $clu_pr_create_options = getenv( 'CLU_PR_CREATE_OPTIONS');
		Logger::warning( "CLU_PR_CREATE_OPTIONS = " . $clu_pr_create_options);
		if ( $clu_pr_create_options ) {
	       		$cmd =  $cmd . ' ' . $clu_pr_create_options;
		}
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( sprintf( 'Failed to create a %s.', $this->getRequestType() ) );
		}

		Logger::success( sprintf( 'Created %s with composer.lock changes.', $this->getRequestType() ) );
	}

	/**
	 * Checks to see if there's an existing PR branch.
	 *
	 * @param string $type Type of value to return.
	 * @return mixed
	 */
	private function checkExistingPRBranch( $type ) {
		Logger::warning( sprintf( 'checkExistingPRBranch %s #%d', 'type', $type ) );
		echo "checkExistingPRBranch type = " . $type ."\n";
		$cmd = $this->provider->pr_list;
		Logger::warning( sprintf( 'checkExistingPRBranch %s #%d', 'cmd', $cmd ) );
		echo "checkExistingPRBranch cmd = " . $cmd ."\n";
		exec($cmd, $output_lines, $return_code);
		Logger::warning( sprintf( 'checkExistingPRBranch %s #%d', 'return_code', $return_code ) );
		echo "checkExistingPRBranch return_code = " . $return_code ."\n";
		if ( 0 !== $return_code ) {
			Logger::error( sprintf( 'Unable to check for existing %ss', $this->getRequestType() ) );
		}
		foreach ($output_lines as $line) {
			Logger::warning( sprintf( 'checkExistingPRBranch %s #%d', 'line', $line ) );
			echo "checkExistingPRBranch line = " . $line ."\n";
			if (preg_match($this->provider->title_pattern, $line, $matches)) {
				if ( 'number' === $type ) {
					return $matches[1];
				} elseif ( 'branch' === $type ) {
					// We will presume the branch name is 'clu-' followed by the date in the issue title.
					return 'clu-' . $matches[2];
				}
			}
		}
		return false;
	}

	/**
	 * Closes an existing PR branch.
	 *
	 * @param string $branch_name Name of the branch.
	 * @return boolean
	 */
	private function closeExistingPRBranch( $branch_name ) {

		Logger::warning( sprintf( 'closeExistingPRBranch %s #%d', '=', $branch_name ) );
		echo "closeExistingPRBranch branch_name = " . $branch_name ."\n";
		$number = $this->checkExistingPRBranch( 'number' );
		Logger::warning( sprintf( 'closeExistingPRBranch %s #%d', '=', $number ) );
		echo "closeExistingPRBranch number = " . $number ."\n";
		if ( ! $number ) {
			Logger::error( sprintf( 'Unable to find existing %s number', $this->getRequestType() ) );
		}
		$cmd = sprintf( $this->provider->pr_close, $number );
		exec($cmd, $output_lines, $return_code);
		if ( 0 !== $return_code ) {
			Logger::error( sprintf( 'Unable to close existing %s #%d: %s', $this->getRequestType(), $number, implode( PHP_EOL, $output_lines ) ) );
		}
		Logger::warning( sprintf( 'Closed existing %s #%d', $this->getRequestType(), $number ) );
		$cmd = 'git push origin --delete ' . escapeshellarg( $branch_name );
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to delete origin branch.' );
		}
	}

	/**
	 * Check the Sensiolabs security component if availble.
	 *
	 * $security_status will be 0 if the code is believed to be good, and
	 * will be non-zero if vulnerabilities were detected (status == 1), or
	 * if the vulnerability status is unknown (status == 127).
	 */
	protected function checkSensiolabsSecurity($composerLockPath, &$security_status) {
		// If the security-checker app is not installed, return an empty message
		exec( 'which security-checker.phar', $outputOfWhich, $return_code );
		if ( $return_code ) {
			$security_status = 127;
			return '';
		}

		exec( 'security-checker.phar security:check --no-ansi ' . $composerLockPath, $output, $is_vulnerable );
		return "\n\n" . implode("\n", $output);
	}

        /**
	 * Check Composer Audit.
	 *
	 * $security_status will be 0 if the code is believed to be good, and
	 * will be non-zero if vulnerabilities were detected.
	 */
	protected function checkComposerAudit(&$is_vulnerable) {
		exec( 'composer audit --locked', $output, $is_vulnerable );
		return "\n\n" . implode("\n", $output);
	}

	/**
	 * Returns 'pull request' or 'merge request', depending on provider.
	 *
	 * @return string
	 */
	private function getRequestType() {
		return isset($this->provider->request_type) ? $this->provider->request_type : 'pull request';
	}

	/**
	 * Checks a `composer.lock` file to see if changes were detected.
	 *
	 * @return boolean
	 */
	private function checkComposerLock() {
		$output = array();
		exec( 'git status -s composer.lock', $output, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Failed to detect changes to composer.lock' );
		}
		if ( empty( $output ) ) {
			Logger::warning( 'No changes detected to composer.lock' );
			return false;
		}
		Logger::warning( 'Detected changes to composer.lock' );
		return true;
	}

	/**
	 * Runs `composer install`.
	 */
	private function runComposerInstall() {
		$args = getenv( 'CLU_COMPOSER_INSTALL_ARGS' ) ? : '--no-dev --no-interaction';
		$args = $args . " --working-dir=build/" . basename($this->target_dir);
		$cmd  = 'cd ../.. && ddev composer install ' . $args;
		Logger::warning( $cmd );
		passthru( $cmd, $return_code );
		if ( 0 !== $return_code ) {
			Logger::error( 'Composer failed to install dependencies.' );
		}
	}

	/**
	 * Runs `composer update`.
	 */
	private function runComposerUpdate() {
		$args = getenv( 'CLU_COMPOSER_UPDATE_ARGS' ) ? : '--no-progress --no-dev --no-interaction';
		$args = $args . " --working-dir=build/" . basename($this->target_dir);
		$cmd  = 'cd ../.. && ddev composer update ' . $args . ' 2>&1';
		Logger::warning( $cmd );
		$output = null;
		exec( $cmd, $output, $return_code );
		$output = implode( PHP_EOL, $output );
                file_put_contents('vendor/update.log', $output);
                echo $output;
		if ( 0 !== $return_code ) {
			$clu_jira_projectid = getenv( 'CLU_JIRA_PROJECTID');
			$clu_jira_assigneeid = getenv( 'CLU_JIRA_ASSIGNEEID');
                        $ret = $this->jiraIssueCreate($clu_jira_projectid, $opts = [
                                 'assignee' => $clu_jira_assigneeid,
                                 # 'description' => "Automated composer dependency updates failed. Please investigate.\n\n" . $output,
				 'description' => "Automated composer dependency updates failed for: " . $this->repo_url . "\nPlease investigate.\n\n" . $output,
				 'summary' => "Composer update failed " . date( 'Y-m-d-H-i' )]);
			Logger::warning("Issue #: " . $ret->key);
			$cmd = 'git reset HEAD --hard';
			Logger::warning( $cmd );
			$output = null;
			exec( $cmd, $output, $return_code );
			$cmd = 'git clean -fd';
			Logger::warning( $cmd );
			$output = null;
			exec( $cmd, $output, $return_code );
			Logger::error( 'Composer failed to update dependencies.' );
		}
		Logger::warning( $output );
		return $output;
	}

        /**
     * JIRA Create Issue
     *
     * @param string $project Project Name
     * @param array $opts
     * @option $assignee Assignee ID
     * @option $description Ussue describtion
     */
    private function jiraIssueCreate($project, $opts = [
        'assignee' => '',
        'description' => 'Automated composer dependency updates.',
        'summary' => 'Composer dependency update'])
    {

        try {
            $issueField = new IssueField();

            $issueField->setProjectKey($project)
                        ->setSummary($opts['summary'])
                        ->setAssigneeAccountId($opts['assignee'])
                        ->setPriorityName("Medium")
                        ->setIssueType("Task")
                        ->setDescription(mb_strimwidth($opts['description'], 0, 32000, "..."))
                    ;

            $issueService = new IssueService();

            return $issueService->create($issueField);

            //If success, Returns a link to the created issue.
        } catch (JiraRestApi\JiraException $e) {
            print("Error Occured! " . $e->getMessage());
        }
    }

}
