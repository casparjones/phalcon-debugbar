<?php
/**
 * User: zhuyajie
 * Date: 15/3/6
 * Time: 12:25
 */

namespace Snowair\Debugbar\Phalcon\Db;

use Phalcon\Db\Adapter;
use \Phalcon\Db\Profiler as PhalconProfiler;
use Snowair\Debugbar\Phalcon\Db\Profiler\Item;

class Profiler extends  PhalconProfiler {

	protected $_failedProfiles=array();
	protected $_stoped=false;
	protected $_lastFailed;
	/**
	 * @var  Item $activeProfile
	 */
	protected $_activeProfile;
	/**
	 * @var Adapter  $_db
	 */
	protected $_db;

	/**
	 * Starts the profile of a SQL sentence
	 *
	 * @param string $sqlStatement
	 * @param null|array   $sqlVariables
	 * @param null|array   $sqlBindTypes
	 *
	 * @return PhalconProfiler
	 */
	public function startProfile($sqlStatement, $sqlVariables = null, $sqlBindTypes = null)
	{
		$latest = $this->_activeProfile;
		if ( !$this->_stoped && $latest) {
			if ( $this->_db ) {
				$pdo = $this->_db->getInternalHandler();
				$latest->setExtra(array(
					'err_code'=>$pdo->errorCode(),
					'err_msg'=>$pdo->errorInfo(),
				));
			}
			$this->_lastFailed = $latest;
			$this->_failedProfiles[] = $latest;
		}

		$activeProfile = new Item();

		$activeProfile->setSqlStatement($sqlStatement);

		if ( is_array($sqlVariables) ) {
			$activeProfile->setSqlVariables($sqlVariables);
		}

		if (is_array($sqlBindTypes)) {
			$activeProfile->setSqlBindTypes($sqlBindTypes);
		}

		$activeProfile->setInitialTime(microtime(true));

		if ( method_exists($this, "beforeStartProfile")) {
			$this->beforeStartProfile($activeProfile);
		}

		$this->_activeProfile = $activeProfile;

		$this->_stoped = false;
		return $this;
	}

	/**
	 * Stops the active profile
	 *
	 * @param array $extra
	 *
	 * @return PhalconProfiler
	 */
	public function stopProfile($extra= array())
	{

		$finalTime = microtime(true);
		$activeProfile = $this->_activeProfile;
		$activeProfile->setFinalTime($finalTime);

		$initialTime = $activeProfile->getInitialTime();
		$this->_totalSeconds = $this->_totalSeconds + ($finalTime - $initialTime);

		if ( $this->_db ) {
			$pdo = $this->_db->getInternalHandler();
			$sql = $activeProfile->getSQLStatement();
			$data = array( 'last_insert_id'=>0, 'affect_rows'=>0, 'source'=>null, );
			if ( stripos( $sql, 'INSERT' )===0 ) {
				$data['last_insert_id'] =  $pdo->lastInsertId();
			}
			if ( stripos( $sql, 'INSERT')===0  || stripos( $sql, 'UPDATE')===0 || stripos( $sql, 'DELETE')===0) {
				$data['affect_rows'] =  $this->_db->affectedRows();
			}
			$activeProfile->setExtra( array_merge($data,$extra));
		}

		$this->_allProfiles[] = $activeProfile;

		if (method_exists($this, "afterEndProfile")) {
			$this->afterEndProfile($activeProfile);
		}

		$this->_stoped = true;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getFailedProfiles() {
		return $this->_failedProfiles;
	}

	/**
	 * @return Item
	 */
	public function getLastFailed() {
		return $this->_lastFailed;
	}

	/**
	 * @param Adapter $db
	 */
	public function setDb( $db ) {
		$this->_db = $db;
	}
}