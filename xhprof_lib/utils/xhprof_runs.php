<?php
//
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

//
// This file defines the interface iXHProfRuns and also provides a default
// implementation of the interface (class XHProfRuns).
//

/**
 * iXHProfRuns interface for getting/saving a XHProf run.
 *
 * Clients can either use the default implementation,
 * namely XHProfRuns_Default, of this interface or define
 * their own implementation.
 *
 * @author Kannan
 */
interface iXHProfRuns
{

    /**
     * Returns XHProf data given a run id ($run) of a given
     * type ($type).
     *
     * Also, a brief description of the run is returned via the
     * $run_desc out parameter.
     */
    public function get_run($run_id, $type, &$run_desc);

    /**
     * Save XHProf data for a profiler run of specified type
     * ($type).
     *
     * The caller may optionally pass in run_id (which they
     * promise to be unique). If a run_id is not passed in,
     * the implementation of this method must generated a
     * unique run id for this saved XHProf run.
     *
     * Returns the run id for the saved XHProf run.
     *
     */
    public function save_run($xhprof_data, $type, $run_id = null);
}


/**
 * XHProfRuns_Default is the default implementation of the
 * iXHProfRuns interface for saving/fetching XHProf runs.
 *
 * This modified version of the file uses a MySQL backend to store
 * the data, it also stores additional information outside the run
 * itself (beyond simply the run id) to make comparisons and run
 * location easier
 *
 * @author Kannan
 * @author Paul Reinheimer (http://blog.preinheimer.com)
 */
class XHProfRuns_Default implements iXHProfRuns
{
    private $dir = '';
    public $prefix = 't11_';


    public function __construct($dir = null)
    {

        // if user hasn't passed a directory location,
        // we use the xhprof.output_dir ini setting
        // if specified, else we default to the directory
        // in which the error_log file resides.

        if (empty($dir)) {
            $dir = ini_get("xhprof.output_dir");
            if (empty($dir)) {

                // some default that at least works on unix...
                $dir = "/tmp";

                xhprof_error(
                    "Warning: Must specify directory location for XHProf runs. " .
                    "Trying {$dir} as default. You can either pass the " .
                    "directory location as an argument to the constructor " .
                    "for XHProfRuns_Default() or set xhprof.output_dir " .
                    "ini param."
                );
            }
        }
        $this->dir = $dir;
    }

    protected function gen_run_id($type)
    {
        return uniqid();
    }


    public function get_run($run_id, $type, &$run_desc)
    {
        $file_name = $this->file_name($run_id, $type);

        if (!file_exists($file_name)) {
            xhprof_error("Could not find file $file_name");
            $run_desc = "Invalid Run Id = $run_id";

            return null;
        }

        $contents = file_get_contents($file_name);
        $run_desc = "XHProf Run (Namespace=$type)";

        return unserialize($contents);
    }


    /**
     * Save the run in the database.
     *
     * @param string $xhprof_data
     * @param mixed $type
     * @param string $run_id
     * @param mixed $xhprof_details
     *
     * @return string
     */
    public function save_run($xhprof_data, $type, $run_id = null)
    {

        // Use PHP serialize function to store the XHProf's
        // raw profiler data.
        $xhprof_data = serialize($xhprof_data);

        if ($run_id === null) {
            $run_id = $this->gen_run_id($type);
        }

        $file_name = $this->file_name($run_id, $type);
        $file      = fopen($file_name, 'w');

        if ($file) {
            fwrite($file, $xhprof_data);
            fclose($file);
        } else {
            xhprof_error("Could not open $file_name\n");
        }

        // echo "Saved run in {$file_name}.\nRun id = {$run_id}.\n";
        return $run_id;
    }

    function list_runs()
    {
        if (is_dir($this->dir)) {
            echo "<hr/>Existing runs:\n<ul>\n";
            $files = glob("{$this->dir}/*.{$this->suffix}");
            usort($files, create_function('$a,$b', 'return filemtime($b) - filemtime($a);'));
            foreach ($files as $file) {
                list($run, $source) = explode('.', basename($file));
                echo '<li><a href="' . htmlentities($_SERVER['SCRIPT_NAME'])
                    . '?run=' . htmlentities($run) . '&source='
                    . htmlentities($source) . '">'
                    . htmlentities(basename($file)) . "</a><small> "
                    . date("Y-m-d H:i:s", filemtime($file)) . "</small></li>\n";
            }
            echo "</ul>\n";
        }
    }


    private function file_name($run_id, $type)
    {

        $file = "$run_id.$type." . $this->suffix;

        if (!empty($this->dir)) {
            $file = $this->dir . "/" . $file;
        }

        return $file;
    }

}

class XHProfRuns_Model extends XHProfRuns_Default
{
    public $run_details = null;
    protected $uri;
    protected $simpleUri;

    /**
     *
     * @var Db_Abstract
     */
    protected $db;

    public function __construct($dir = null)
    {
        $this->db();
    }

    protected function db()
    {
        global $_xhprof;
        require_once XHPROF_LIB_ROOT . '/utils/Db/' . $_xhprof['dbadapter'] . '.php';

        $class    = self::getDbClass();
        $this->db = new $class($_xhprof);
        $this->db->connect();
    }

    public function setUri($uri)
    {
        $this->uri = $uri;
    }

    public function setSimpleUri($simpleUri)
    {
        $this->simpleUri = $simpleUri;
    }

    public function getSimpleUri()
    {
        return $this->simpleUri;
    }

    public function getUri()
    {
        return $this->uri;
    }


    public static function getNextAssoc($resultSet)
    {
        $class = self::getDbClass();

        return $class::getNextAssoc($resultSet);
    }

    public static function getDbClass()
    {
        global $_xhprof;

        return 'Db_' . $_xhprof['dbadapter'];
    }

    /**
     * When setting the `id` column, consider the length of the prefix you're specifying in $this->prefix
     *
     *
     * CREATE TABLE `details` (
     * `id` char(17) NOT NULL,
     * `url` varchar(255) default NULL,
     * `c_url` varchar(255) default NULL,
     * `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
     * `server_name` varchar(64) default NULL,
     * `perfdata` MEDIUMBLOB,
     * `type` tinyint(4) default NULL,
     * `cookie` BLOB,
     * `post` BLOB,
     * `get` BLOB,
     * `pmu` int(11) unsigned default NULL,
     * `wt` int(11) unsigned default NULL,
     * `cpu` int(11) unsigned default NULL,
     * `server_id` char(3) NOT NULL default 't11',
     * `aggregateCalls_include` varchar(255) DEFAULT NULL,
     * PRIMARY KEY  (`id`),
     * KEY `url` (`url`),
     * KEY `c_url` (`c_url`),
     * KEY `cpu` (`cpu`),
     * KEY `wt` (`wt`),
     * KEY `pmu` (`pmu`),
     * KEY `timestamp` (`timestamp`)
     * ) ENGINE=MyISAM DEFAULT CHARSET=utf8;

     */

    public function save($xhprof_data)
    {
        global $_xhprof;

        if (extension_loaded('xhprof')) {
            $profiler_namespace = $_xhprof['namespace']; // namespace for your application
            $this->save_run($xhprof_data, $profiler_namespace, null, $_xhprof);
        }
    }

    public function save_run($xhprof_data, $type, $run_id = null, $xhprof_details = null)
    {
        global $_xhprof;
        $sql = array();

        /*
        Session data is ommitted purposefully, mostly because it's not likely that the data
        that resides in $_SESSION at this point is the same as the data that the application
        started off with (for most apps, it's likely that session data is manipulated on most
        pageloads).

        The goal of storing get, post and cookie is to help explain why an application chose
        a particular code execution path, pehaps it was a poorly filled out form, or a cookie that
        overwrote some default parameters. So having them helps. Most applications don't push data
        back into those super globals, so we're safe(ish) storing them now.

        We can't just clone the session data in header.php to be sneaky either, starting the session
        is an application decision, and we don't want to go starting sessions where none are needed
        (not good performance wise). We could be extra sneaky and do something like:
        if(isset($_COOKIE['phpsessid']))
        {
            session_start();
            $_xhprof['session_data'] = $_SESSION;
        }
        but starting session support really feels like an application level decision, not one that
        a supposedly unobtrusive profiler makes for you.

        */

        if (!isset($_xhprof['serializer']) || strtolower($_xhprof['serializer'] == 'php')) {
            $sql['get']    = serialize($_GET);
            $sql['cookie'] = serialize($_COOKIE);

            //This code has not been tested
            if (isset($_xhprof['savepost']) && $_xhprof['savepost']) {
                $sql['post'] = serialize($_POST);
            } else {
                $sql['post'] = serialize(array("Skipped" => "Post data omitted by rule"));
            }
        } else {
            $sql['get']    = json_encode($_GET);
            $sql['cookie'] = json_encode($_COOKIE);

            //This code has not been tested
            if (isset($_xhprof['savepost']) && $_xhprof['savepost']) {
                $sql['post'] = json_encode($_POST);
            } else {
                $sql['post'] = json_encode(array("Skipped" => "Post data omitted by rule"));
            }
        }

        $sql['pmu'] = isset($xhprof_data['main()']['pmu']) ? $xhprof_data['main()']['pmu'] : '';
        $sql['wt']  = isset($xhprof_data['main()']['wt']) ? $xhprof_data['main()']['wt'] : '';
        $sql['cpu'] = isset($xhprof_data['main()']['cpu']) ? $xhprof_data['main()']['cpu'] : '';

        // The value of 2 seems to be light enugh that we're not killing the server, but still gives us lots of breathing room on
        // full production code.
        if (!isset($_xhprof['serializer']) || strtolower($_xhprof['serializer'] == 'php')) {
            $sql['data'] = gzcompress(serialize($xhprof_data), 2);
        } else {
            $sql['data'] = json_encode($xhprof_data);
        }

        $sname = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';

        $sql['url']                    = $this->getUri();
        $sql['c_url']                  = $this->getSimpleUri();
        $sql['servername']             = $sname;
        $sql['type']                   = (int)(isset($xhprof_details['type']) ? $xhprof_details['type'] : 0);
        $sql['timestamp']              = $_SERVER['REQUEST_TIME'];
        $sql['server_id']              = $_xhprof['servername'];
        $sql['aggregateCalls_include'] =
            getenv('xhprof_aggregateCalls_include') ? getenv('xhprof_aggregateCalls_include') : '';

        return $this->saveInternal($sql, $type, $run_id);
    }

    protected function calculatePercentile($details)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];
        $limit = (int)($details['count'] / 20);
        $query =
            "SELECT `{$details['column']}` as `value` FROM `{$table}` WHERE `{$details['type']}` = '{$details['url']}' ORDER BY `{$details['column']}` DESC LIMIT $limit, 1";
        $rs    = $this->db->query($query);
        $row   = $this->db->getNextAssoc($rs);

        return $row['value'];
    }


    /**
     * Get comparative information for a given URL and c_url, this information will be used to display stats like how many calls a URL has,
     * average, min, max execution time, etc. This information is pushed into the global namespace, which is horribly hacky.
     *
     * @param string $url
     * @param string $c_url
     *
     * @return array
     */
    public function getRunComparativeData($url, $c_url)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        $url   = $this->db->escape($url);
        $c_url = $this->db->escape($c_url);
        //Runs same URL
        //  count, avg/min/max for wt, cpu, pmu
        $query      =
            "SELECT count(`id`), avg(`wt`), min(`wt`), max(`wt`),  avg(`cpu`), min(`cpu`), max(`cpu`), avg(`pmu`), min(`pmu`), max(`pmu`) FROM `{$table}` WHERE `url` = '$url'";
        $rs         = $this->db->query($query);
        $row        = $this->db->getNextAssoc($rs);
        $row['url'] = $url;

        $row['95(`wt`)']  = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'wt', 'type' => 'url', 'url' => $url)
        );
        $row['95(`cpu`)'] = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'cpu', 'type' => 'url', 'url' => $url)
        );
        $row['95(`pmu`)'] = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'pmu', 'type' => 'url', 'url' => $url)
        );

        global $comparative;
        $comparative['url'] = $row;
        unset($row);

        //Runs same c_url
        //  count, avg/min/max for wt, cpu, pmu
        $query            =
            "SELECT count(`id`), avg(`wt`), min(`wt`), max(`wt`),  avg(`cpu`), min(`cpu`), max(`cpu`), avg(`pmu`), min(`pmu`), max(`pmu`) FROM `{$table}` WHERE `c_url` = '$c_url'";
        $rs               = $this->db->query($query);
        $row              = $this->db->getNextAssoc($rs);
        $row['url']       = $c_url;
        $row['95(`wt`)']  = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'wt', 'type' => 'c_url', 'url' => $c_url)
        );
        $row['95(`cpu`)'] = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'cpu', 'type' => 'c_url', 'url' => $c_url)
        );
        $row['95(`pmu`)'] = $this->calculatePercentile(
            array('count' => $row['count(`id`)'], 'column' => 'pmu', 'type' => 'c_url', 'url' => $c_url)
        );

        $comparative['c_url'] = $row;
        unset($row);

        return $comparative;
    }


    /**
     * Retreives a run from the database,
     *
     * @param string $run_id unique identifier for the run being requested
     * @param mixed $type
     * @param mixed $run_desc
     *
     * @return mixed
     */
    public function get_run($run_id, $type, &$run_desc)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        $run_id    = $this->db->escape($run_id);
        $query     = "SELECT * FROM `{$table}` WHERE `id` = '$run_id'";
        $resultSet = $this->db->query($query);
        $data      = $this->db->getNextAssoc($resultSet);

        //The Performance data is compressed lightly to avoid max row length
        if (!isset($_xhprof['serializer']) || strtolower($_xhprof['serializer'] == 'php')) {
            $contents = unserialize(gzuncompress($data['perfdata']));
        } else {
            $contents = json_decode($data['perfdata'], true);
        }

        //This data isnt' needed for display purposes, there's no point in keeping it in this array
        unset($data['perfdata']);

        // The same function is called twice when diff'ing runs. In this case we'll populate the global scope with an array
        if (is_null($this->run_details)) {
            $this->run_details = $data;
        } else {
            $this->run_details[0] = $this->run_details;
            $this->run_details[1] = $data;
        }

        $run_desc = "XHProf Run (Namespace=$type)";
        $this->getRunComparativeData($data['url'], $data['c_url']);

        return array($contents, $data);
    }

    /**
     * This function gets runs based on passed parameters, column data as key, value as the value. Values
     * are escaped automatically. You may also pass limit, order by, group by, or "where" to add those values,
     * all of which are used as is, no escaping.
     *
     * @param array $stats Criteria by which to select columns
     *
     * @return resource
     */
    public function getRuns($stats)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        if (isset($stats['select'])) {
            $query = "SELECT {$stats['select']} FROM `{$table}` ";
        } else {
            $query = "SELECT * FROM `{$table}` ";
        }

        $skippers = array("limit", "order by", "group by", "where", "select");
        $hasWhere = false;

        foreach ($stats AS $column => $value) {

            if (in_array($column, $skippers)) {
                continue;
            }
            if ($hasWhere === false) {
                $query .= " WHERE ";
                $hasWhere = true;
            } elseif ($hasWhere === true) {
                $query .= "AND ";
            }
            if (strlen($value) == 0) {
                $query .= $column;
            }

            $value = $this->db->escape($value);
            $query .= " `$column` = '$value' ";
        }

        if (isset($stats['where'])) {
            if ($hasWhere === false) {
                $query .= " WHERE ";
                $hasWhere = true;
            } else {
                $query .= " AND ";
            }
            $query .= $stats['where'];
        }

        if (isset($stats['group by'])) {
            $query .= " GROUP BY `{$stats['group by']}` ";
        }

        if (isset($stats['order by'])) {
            $query .= " ORDER BY `{$stats['order by']}` DESC";
        }

        if (isset($stats['limit'])) {
            $query .= " LIMIT {$stats['limit']} ";
        }

        $resultSet = $this->db->query($query);

        return $resultSet;
    }

    /**
     * Obtains the pages that have been the hardest hit over the past N days, utalizing the getRuns() method.
     *
     * @param array $criteria An associative array containing, at minimum, type, days, and limit
     *
     * @return resource The result set reprsenting the results of the query
     */
    public function getHardHit($criteria)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        //call thing to get runs
        $criteria['select'] =
            "distinct(`{$criteria['type']}`), count(`{$criteria['type']}`) AS `count` , sum(`wt`) as total_wall, avg(`wt`) as avg_wall";
        unset($criteria['type']);
        $criteria['where'] = $this->db->dateSub($criteria['days']) . " <= `timestamp`";
        unset($criteria['days']);
        $criteria['group by'] = "url";
        $criteria['order by'] = "count";
        $resultSet            = $this->getRuns($criteria);

        return $resultSet;
    }

    public function getDistinct($data)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        $sql['column'] = $this->db->escape($data['column']);
        $query         = "SELECT DISTINCT(`{$sql['column']}`) FROM `{$table}`";
        $rs            = $this->db->query($query);

        return $rs;
    }

    /**
     * Get stats (pmu, ct, wt) on a url or c_url
     *
     * @param array $data An associative array containing the limit you'd like to set for the queyr, as well as either c_url or url for the desired element.
     *
     * @return resource result set from the database query
     */
    public function getUrlStats($data)
    {
        $data['select'] = '`id`, ' . $this->db->unixTimestamp('timestamp') . ' as `timestamp`, `pmu`, `wt`, `cpu`';
        $rs             = $this->getRuns($data);

        return $rs;
    }

    protected function saveInternal($sql, $type, $run_id = null)
    {
        global $_xhprof;
        $table = $_xhprof['table_name'];

        foreach ($sql as $key => &$val) {
            $this->db->escape($val);
        }

        $sql['run_id'] = $run_id === null ? $this->gen_run_id($type) : $run_id;

        $query =
            "INSERT INTO `{$table}` (`id`, `url`, `c_url`, `timestamp`, `server_name`, `perfdata`, `type`, `cookie`, `post`, `get`, `pmu`, `wt`, `cpu`, `server_id`, `aggregateCalls_include`)
                              VALUES('{$sql['run_id']}', '{$sql['url']}', '{$sql['c_url']}', FROM_UNIXTIME('{$sql['timestamp']}'), '{$sql['servername']}', '{$sql['data']}', '{$sql['type']}', '{$sql['cookie']}', '{$sql['post']}', '{$sql['get']}', '{$sql['pmu']}', '{$sql['wt']}', '{$sql['cpu']}', '{$sql['server_id']}', '{$sql['aggregateCalls_include']}')";

        $this->db->query($query);
        if ($this->db->affectedRows($this->db->linkID) == 1) {
            return $run_id;
        } else {
            global $_xhprof;
            if ($_xhprof['display'] === true) {
                echo "Failed to insert: $query <br>\n";
            }

            return -1;
        }
    }

}

class XHProf_Profiler
{
    protected $simpleUri;
    /**
     * @var XHProfRuns_Model
     */
    protected static $xhprofModel;

    /**
     * @param $config
     *
     * @return XHProfRuns_Model
     */
    public function getXhprofModel($config)
    {
        if (!static::$xhprofModel) {
            global $_xhprof;
            $_xhprof = $config;
            static::$xhprofModel = new XHProfRuns_Model();
        }

        return static::$xhprofModel;
    }

    public function getSimpleUri($url)
    {
        if ($this->simpleUri) {
            return $this->simpleUri;
        }

        return $url;
    }

    public function getUri()
    {
        return isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF'];
    }

    public function setSimpleUri($uri)
    {
        $this->simpleUri = $uri;
    }

    public function beginProfile($url = null)
    {
        global $_xhprof;
        if ($url) {
            $this->simpleUri = $url;
        }

        //Display warning if extension not available
        if (extension_loaded('xhprof')) {
            include_once XHPROF_LIB_ROOT . '/utils/xhprof_lib.php';
            include_once XHPROF_LIB_ROOT . '/utils/xhprof_runs.php';
            if (isset($ignoredFunctions) && is_array($ignoredFunctions) && !empty($ignoredFunctions)) {
                xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY, array('ignored_functions' => $ignoredFunctions));
            } else {
                xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
            }
        } elseif (!extension_loaded('xhprof') && $_xhprof['display'] === true) {
            //$message = 'Warning! Unable to profile run, xhprof extension not loaded';
            //trigger_error($message, E_USER_WARNING);
        }
    }

    public function endProfile($url)
    {
        if (extension_loaded('xhprof')) {
            return xhprof_disable();
        } else {
            return false;
        }
    }

}