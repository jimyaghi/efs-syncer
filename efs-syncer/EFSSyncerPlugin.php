<?php
/**
 * بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيم
 *
 * Created by Jim Yaghi
 * Date: 2021-10-07
 * Time: 11:56
 *
 */

namespace YL {

	class EFSSyncerPlugin {

		public const OPERATION_TO_EFS = 'to_efs';

		public const OPERATION_FROM_EFS = 'from_efs';

		public const STATUS_QUEUED = 'queued';

		public const STATUS_STARTED = 'started';

		public const STATUS_COMPLETE = 'complete';

		public const LOCK_TIME_LIMIT = 30 * MINUTE_IN_SECONDS;
		public const INSTANCE_TIME_LIMIT = DAY_IN_SECONDS;

		public const LOCAL_ROOT = '/var/www';
		public const REMOTE_ROOT = '/mnt/efs/fs1';

		/**
		 * @var EFSSyncerPlugin
		 */
		private static $instance;


		/**
		 * Initialises our plugin
		 */
		private function __construct() {
			$this->attach_hooks();
		}

		/**
		 * instantiates an instance of this plugin and ensures only one such instance exists
		 * @return EFSSyncerPlugin
		 */
		public static function getInstance() {
			if ( static::$instance ) {
				static::$instance = new static();
			}

			return static::$instance;
		}

		/**
		 * Gets the instance ID of our compute unit
		 *
		 * @return false|string
		 */
		public function getInstanceID() {
			return file_get_contents( "http://instance-data/latest/meta-data/instance-id" );
		}

		/**
		 * Callback when local file changes occur so we can trigger an upstream sync job to the network drive
		 */
		public function syncToEFS() {
			$this->addJob( [ 'type' => static::OPERATION_TO_EFS ] );
		}

		/**
		 * Adds a job to the queue
		 *
		 * @param $job_to_add
		 *
		 * @return array
		 */
		public function addJob( $job_to_add ) {
			$new_time                  = microtime( true );
			$myInstanceID              = $this->getInstanceID();
			$defaults                  = [
				'created_at'  => $new_time,
				'instance_id' => $myInstanceID,
				'type'        => static::OPERATION_FROM_EFS,
				'status'      => static::STATUS_QUEUED,
				'invoked_by'  => null
			];
			$job_to_add                = $job_to_add + $defaults;
			$job_to_add['instance_id'] = $job_to_add['instance_id'] ?: $myInstanceID;
			$jobs                      = $this->getJob();
			$jobs[ $new_time ]         = $job_to_add;
			ksort( $jobs, SORT_NUMERIC );
			$this->update_option( 'efss_jobs_' . $job_to_add['instance_id'], $jobs );

			return $job_to_add;
		}

		/**
		 * @param float $jobIDMicroTime
		 * @param array $job_to_update
		 *
		 * @return array|false
		 */
		public function updateJob( $jobIDMicroTime = null, $job_to_update = [] ) {
			$job = $this->getJob( $jobIDMicroTime, $job_to_update['instance_id'] ?? null );
			if ( $job === false ) {
				$job = [];
			}

			return $this->addJob( array_merge( $job, $job_to_update ) );
		}

		/**
		 * Retrieves a job, given its ID and instance_id. If the job ID is not provided, an array is returned with all
		 * jobs for the given instance_id. if an instance_id is not provided, we default to the current compute
		 * instance ID.
		 *
		 * @param null $jobIDMicroTime
		 * @param null $instance_id
		 *
		 * @return false|array
		 */
		public function getJob( $jobIDMicroTime = null, $instance_id = null ) {
			if ( $instance_id === null ) {
				$instance_id = $this->getInstanceID();
			}

			$jobs = $this->get_option( 'efss_jobs_' . $instance_id, [] );

			return $jobIDMicroTime === null ? $jobs : ( $jobs[ $jobIDMicroTime ] ?? false );
		}

		/**
		 * Deletes a job given by ID from the provided instance. If an instance_id is not provided, the default is the
		 * current compute instance's ID
		 *
		 * @param $jobIDMicroTime
		 * @param $instance_id
		 *
		 * @return array|false
		 */
		public function deleteJob( $jobIDMicroTime, $instance_id = null ) {
			if ( $instance_id === null ) {
				$instance_id = $this->getInstanceID();
			}

			$jobs = $this->getJob( null, $instance_id );
			$job  = $jobs[ $jobIDMicroTime ] ?? false;
			unset( $jobs[ $jobIDMicroTime ] );
			$this->update_option( 'efss_jobs_' . $instance_id, $jobs );

			return $job;
		}

		/**
		 * Ensures we are registered as listening for jobs
		 */
		public function registerSelf() {
			$this->update_option( 'efss_last_alive_' . $this->getInstanceID(), microtime( true ) );
		}

		public function deleteInvokedJobs( $instance_to_expire ) {
			$expired_instance_jobs = array_keys( $this->getJob( null, $instance_to_expire ) );
			$instance_ids          = $this->getAllInstanceIds();
			foreach ( $instance_ids as $id2 ) {
				$jobs = $this->getJob( null, $id2 );
				foreach ( $jobs as $jobId => $job ) {
					if ( in_array( $job['invoked_by'], $expired_instance_jobs ) ) {
						$this->deleteJob( $jobId, $id2 );
					}
				}
			}
		}

		public function deleteDeadInstances() {
			$instance_ids = $this->getAllInstanceIds();
			foreach ( $instance_ids as $id ) {
				$last_alive = $this->get_option( 'efss_last_alive_' . $id, false );
				if ( $last_alive === false || ( microtime( true ) - $last_alive ) <= static::INSTANCE_TIME_LIMIT ) {
					continue;
				}
				$this->deleteInvokedJobs( $id );

				delete_option( 'efss_jobs_' . $id );
				delete_option( 'efss_last_alive_' . $id );
			}
		}

		/**
		 * Callback for the init hook. Handles starting queued sync job, completing started sync job, expiring old
		 * sync jobs, and queuing downstream sync jobs.
		 */
		public function handleSyncJobs() {

			$myId = $this->getInstanceID();

			// if there is a queued job for us, and it's our turn, let's do it
			while (
				$this->isSyncAllowed()
				&& ( $job = $this->getOldestQueuedJob() )
				&& $job !== false
				&& $job['instance_id'] === $myId
			) {
				$this->disallowSync();
				$jobId = $job['created_at'];
				$this->markSyncStarted( $jobId );
				$this->doSync( $job['type'] );

				// restart the server if local files have changed
				if ( $job['type'] === static::OPERATION_FROM_EFS ) {
					shell_exec( "service apache stop && service apache start && service php7.4-fpm force-reload" );
				}

				$this->markSyncComplete( $jobId );

				// queue a downstream sync for all currently live instances...
				foreach ( $this->getAllInstanceIds() as $id ) {
					if ( $id === $myId ) {
						continue;
					}
					$this->addJob( [
						'type'        => static::OPERATION_FROM_EFS,
						'instance_id' => $id,
						'invoked_by'  => $myId
					] );
				}

				// release lock
				$this->allowSync();
			}
		}

		/**
		 * Does the actual sync using rsync
		 *
		 * @param $operationType
		 *
		 * @return false|string|null
		 */
		private function doSync( $operationType ) {
			if ( $operationType === static::OPERATION_FROM_EFS ) {
				$fromDir = static::REMOTE_ROOT;
				$toDir   = static::LOCAL_ROOT;
			} else {
				$fromDir = static::LOCAL_ROOT;
				$toDir   = static::REMOTE_ROOT;
			}
			$fromDir = rtrim( $fromDir, '/' );
			$toDir   = rtrim( $toDir, '/' );

			$dirs = scandir( static::LOCAL_ROOT );
			foreach ( $dirs as $dir ) {
				if ( $dir === '.'
				     || $dir === '..'
				     || ! is_dir( static::LOCAL_ROOT . "/{$dir}" )
				     || ! is_dir( static::REMOTE_ROOT . "/{$dir}" ) ) {
					continue;
				}
				// do the operation and wait
				echo "rsync -aWPAXE --delete --inplace \"{$fromDir}/$dir/\" \"{$toDir}/$dir\" >> /var/log/yaghilabs/{$operationType}.log 2>&1" ;
				shell_exec( "rsync -aWPAXE --dry-run --delete --inplace \"{$fromDir}/$dir/\" \"{$toDir}/$dir\" >> /var/log/yaghilabs/{$operationType}.log 2>&1" );
			}
		}


		/**
		 * If a lock has been taken out and took too long to release, we assume a dead process and delete it
		 */
		public function releaseExpiredLocks() {
			$lockTime = $this->get_option( 'efss_disallow_sync', PHP_FLOAT_MAX );
			if ( ( microtime( true ) - $lockTime ) > static::LOCK_TIME_LIMIT ) {
				$this->update_option( 'efss_disallow_sync', false );
			}
		}

		/**
		 * Updates the given jobId so it now has a status of started
		 *
		 * @param $jobId
		 *
		 * @return array|false
		 */
		public function markSyncStarted( $jobId ) {
			return $this->updateJob( $jobId, [ 'status' => static::STATUS_STARTED ] );
		}

		/**
		 * Updates the given jobId so it now has a status of complete
		 *
		 * @param $jobId
		 *
		 * @return array|false
		 */
		public function markSyncComplete( $jobId ) {
			return $this->updateJob( $jobId, [ 'status' => static::STATUS_COMPLETE ] );
		}


		/**
		 * Finds a queued job first in queue and returns it
		 *
		 * @return false|mixed
		 */
		public function getOldestQueuedJob() {
			$instance_ids = $this->getAllInstanceIds();

			// flatten the jobs array
			$minTime     = PHP_FLOAT_MAX;
			$minInstance = PHP_INT_MAX;
			$minJob      = null;

			foreach ( $instance_ids as $instance_id ) {
				$instance_jobs = $this->getJob( null, $instance_id );
				foreach ( $instance_jobs as $time => $job ) {
					if ( $job['status'] !== static::STATUS_QUEUED ) {
						continue;
					}

					if ( $time < $minTime
					     || ( $time === $minTime && strcmp( $minInstance, $instance_id ) < 0 ) ) {
						$minTime     = $time;
						$minInstance = $instance_id;
						$minJob      = $job;
					}
				}
			}

			return ( $minJob === null ? false : $minJob );
		}

		/**
		 * Takes out a lock on the EFS server so that no one can do a sync while an operation is going on
		 */
		private function disallowSync() {
			$this->update_option( 'efss_disallow_sync', microtime( true ) );
		}

		/**
		 * Release the lock on the EFS server so any other queued jobs can start
		 */
		private function allowSync() {
			$this->update_option( 'efss_disallow_sync', false );
		}

		/**
		 * Tells us if we are allowed to begin a sync job
		 * @return bool
		 */
		private function isSyncAllowed() {
			return $this->get_option( 'efss_disallow_sync', false ) === false;
		}

		/**
		 * Gets all the instances with queued jobs
		 *
		 * @return string[]
		 */
		public function getAllInstanceIds() {
			global $wpdb;
			$job_settings_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'efss\_last\_alive\_%'" );

			return preg_replace( '/efss_last_alive_/', '', $job_settings_names );
		}

		/**
		 * A cache-less version of WP get_option, useful when multiple nodes are editing options and WP is not aware
		 * of those edits so it thinks it can use the cached value.
		 *
		 * @param $option_name
		 * @param false $default_value
		 *
		 * @return false|mixed|void
		 */
		private function get_option( $option_name, $default_value = false ) {
			$GLOBALS['wp_object_cache']->delete( $option_name, 'options' );

			return get_option( $option_name, $default_value );
		}

		/**
		 * A cacheless version of update_option. by making autoload:false, WP doesn't load it into its autoload cache
		 *
		 * @param $option_name
		 * @param $option_value
		 *
		 * @return bool
		 */
		private function update_option( $option_name, $option_value ) {
			return update_option( $option_name, $option_value, false );
		}

		/**
		 * attaches the Wordpress hooks that allow us to operate when file system changes occur
		 */
		public function attach_hooks() {
			do_action('init', [$this, 'register_cron']);
			do_action('init', [ $this, 'registerSelf' ] );

			do_action( 'delete_plugin', [ $this, 'syncToEFS' ] );
			do_action( 'upgrader_process_complete', [ $this, 'syncToEFS' ] );
			do_action( 'deleted_theme', [ $this, 'syncToEFS' ] );
		}
	}
}
