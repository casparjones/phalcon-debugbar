<?php
/**
 * User: zhuyajie
 * Date: 15/3/6
 * Time: 12:25
 */

namespace Snowair\Debugbar\Phalcon\Db;

use Phalcon\Db\Adapter\AdapterInterface as Adapter;
use Phalcon\Db\Column;
use Phalcon\Db\Profiler as PhalconProfiler;
use Phalcon\Support\Version;
use Snowair\Debugbar\Phalcon\Db\Profiler\Item;

class Profiler extends PhalconProfiler
{
    protected $_failedProfiles = [];
    protected $_stoped = false;
    protected $_lastFailed;
    protected $_explainQuery = false;

    /**
     * @var  Item
     */
    protected $_activeProfile;

    /**
     * @var Adapter
     */
    protected $_db;

    public function handleFailed()
    {
        $latest = $this->_activeProfile;
        if (!$this->_stoped && $latest) {
            if ($this->_db) {
                $pdo = $this->_db->getInternalHandler();
                $latest->setExtra([
                    'err_code' => $pdo->errorCode(),
                    'err_msg' => $pdo->errorInfo(),
                    'connection' => $this->getConnectionInfo(),
                ]);
            }
            $this->_lastFailed = $latest;
            $this->_failedProfiles[] = $latest;
        }
    }

    public function getConnectionInfo()
    {
        $info = $this->_db->getDescriptor();
        if (empty($info['host'])) {
            return $info['dbname'];
        } elseif (isset($info['host']) && isset($info['port']) && !in_array($info['port'], [3306, 1521, 5432, 1433])) {
            $info['host'] .= ':'.$info['port'];
        }
        if (isset($info['dbname'])) {
            return $info['host'].'/'.$info['dbname'];
        } else {
            return $info['host'];
        }
    }

    /**
     * Starts the profile of a SQL sentence
     *
     * @param string $sqlStatement
     * @param mixed   $sqlVariables
     * @param mixed   $sqlBindTypes
     *
     * @return PhalconProfiler
     */
    public function startProfile(string $sqlStatement, $sqlVariables = null, $sqlBindTypes = null): PhalconProfiler
    {
        $this->handleFailed();
        $activeProfile = new Item();

        if (!$sqlVariables) {
            $sqlVariables = $this->_db->getSqlVariables();
        }

        $activeProfile->setSqlStatement($sqlStatement);
        $activeProfile->setRealSQL($this->getRealSql($sqlStatement, $sqlVariables, $sqlBindTypes));

        if (is_array($sqlVariables)) {
            $activeProfile->setSqlVariables($sqlVariables);
        }

        if (is_array($sqlBindTypes)) {
            $activeProfile->setSqlBindTypes($sqlBindTypes);
        }

        $activeProfile->setInitialTime(microtime(true));

        if (method_exists($this, 'beforeStartProfile')) {
            $this->beforeStartProfile($activeProfile);
        }

        $this->_activeProfile = $activeProfile;

        $this->_stoped = false;

        return $this;
    }

    public function getRealSql($sql, $variables, $sqlBindTypes)
    {
        if (!$variables) {
            return $sql;
        }
        $pdo = $this->_db->getInternalHandler();
        $indexes = [];
        $keys = [];
        foreach ($variables as $key => $value) {
            if (is_array($value) && (new Version())->getId() >= 5000000) {
                foreach ($value as $k => $v) {
                    $keys[':'.$key.$k] = $pdo->quote($v);
                }
            } else {
                $type = isset($sqlBindTypes[$key]) ? $sqlBindTypes[$key] : null;
                if (is_numeric($key)) {
                    $indexes[$key] = $this->quote($pdo, $value, $type);
                } else {
                    if (is_numeric(substr($key, 1))) {
                        $keys[$key] = $this->quote($pdo, $value, $type);
                    } elseif (substr($key, 0, 1) === ':') {
                        $keys[$key] = $this->quote($pdo, $value, $type);
                    } else {
                        $keys[':'.$key] = $this->quote($pdo, $value, $type);
                    }
                }
            }
        }
        $splited = preg_split('/(?=\?)|(?<=\?)/', $sql);

        $result = [];
        foreach ($splited as $key => $value) {
            if ($value == '?') {
                $result[$key] = array_shift($indexes);
            } else {
                $result[$key] = $value;
            }
        }
        $result = implode(' ', $result);
        $result = strtr($result, $keys);

        return $result;
    }

    public function quote($pdo, $value, $type = null)
    {
        if ($type === null) {
            return $pdo->quote($value);
        }
        switch($type) {
            case Column::BIND_SKIP:
                break;
            case Column::BIND_PARAM_INT:
                $value = (int) $value;
                break;
            case Column::BIND_PARAM_NULL:
                $value = 'null';
                break;
            case Column::BIND_PARAM_BOOL:
                $value = $value ? 'true' : 'false';
                break;
            default:
                $value = $pdo->quote($value);
        }

        return $value;
    }

    /**
     * Stops the active profile
     *
     * @return PhalconProfiler
     */
    public function stopProfile(): PhalconProfiler
    {
        $finalTime = microtime(true);
        $activeProfile = $this->_activeProfile;
        if ($activeProfile === null) {
            return $this;
        }
        $activeProfile->setFinalTime($finalTime);

        $initialTime = $activeProfile->getInitialTime();

        if (!isset($this->totalSeconds)) {
            $this->totalSeconds = 0;
        }

        $this->totalSeconds = $this->totalSeconds + ($finalTime - $initialTime);

        if ($this->_db) {
            $pdo = $this->_db->getInternalHandler();
            $sql = $activeProfile->getSQLStatement();
            $data = ['last_insert_id' => 0, 'affect_rows' => 0];
            $data['connection'] = $this->getConnectionInfo();
            if (stripos($sql, 'INSERT') === 0) {
                $data['last_insert_id'] = $pdo->lastInsertId();
            }
            if (stripos($sql, 'INSERT') === 0 || stripos($sql, 'UPDATE') === 0 || stripos($sql, 'DELETE') === 0) {
                $data['affect_rows'] = $this->_db->affectedRows();
            }
            if (stripos($sql, 'SELECT') === 0 && $this->_explainQuery) {
                try {
                    $stmt = $pdo->query('explain '.$activeProfile->getRealSQL());
                    $data['explain'] = $stmt->fetchAll(\PDO::FETCH_CLASS);
                } catch (\Exception $e) {
                }
            }
            $activeProfile->setExtra($data);
        }

        $this->allProfiles[] = $activeProfile;

        if (method_exists($this, 'afterEndProfile')) {
            $this->afterEndProfile($activeProfile);
        }

        $this->_stoped = true;

        return $this;
    }

    /**
     * @return array
     */
    public function getFailedProfiles()
    {
        return $this->_failedProfiles;
    }

    /**
     * @return Item
     */
    public function getLastFailed()
    {
        return $this->_lastFailed;
    }

    /**
     * @param Adapter $db
     */
    public function setDb($db)
    {
        $this->_db = $db;
    }

    public function setSource($source)
    {
        $this->_activeProfile->setExtra(['source' => $source]);
    }

    /**
     * @param bool $explainQuery
     */
    public function setExplainQuery($explainQuery)
    {
        $this->_explainQuery = (bool) $explainQuery;
    }
}
