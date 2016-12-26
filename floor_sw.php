<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
class Floor
{
	protected $task_worker_num;
	protected $worker_num;
	protected $free_table;
	protected $map_table;	// fd 和 task 的对应关系

	protected $busy_table;	
	protected $wait_queue = array(); // 等待队列

	protected $db_host;
	protected $db_user;
	protected $db_pwd;
	protected $db_name;
	protected $db_port;

	protected $port;
	protected $serv;
	private $pdo = null;
	protected $request_cnt;

	protected $elevator_config;

	function __construct(array $config) {
		$this->elevator_config = require_once("./elevator_config.php");

		$this->port = isset($config['port']) ? $config['port'] : 9500;
		$this->worker_num = isset($config['worker_num']) ? $config['worker_num'] : 1;
		$this->task_worker_num = isset($config['task_worker_num']) ? $config['task_worker_num'] : 8;
		$this->db_host = isset($config['db_host']) ? $config['db_host'] : "127.0.0.1";
		$this->db_user = isset($config['db_user']) ? $config['db_user'] : "root";
		$this->db_pwd = isset($config['db_pwd']) ? $config['db_pwd'] : "";
		$this->db_name = isset($config['db_name']) ? $config['db_name'] : "floor";
		$this->db_port = isset($config['db_port']) ? $config['db_port'] : 3306;

		$this->free_table = new swoole_table(1024);
		$this->free_table->column("task_id", swoole_table::TYPE_STRING, 1000);
		$this->free_table->creat();
		for ($i=0; $i < $this->task_worker_num; $i++) { 
			$free[] = $i;
		}
		$this->free_table->set("task_id", array("task_id" => json_encode($free)));

		$this->map_table = new swoole_table(1024); 	// 记录 fd 和 busy_id 的对应关系
		$this->map_table->column("busy_id", swoole_table::TYPE_STRING, 1000);
		$this->free_table->create();
		$this->map_table->set("busy_id", array("busy_id" => json_encode(array())));
 	} 

 	public function run() {
 		$this->serv = new swoole_server("127.0.0.1", $this->port);
 		$this->serv->set(array(
 			"worker_num" => $this->worker_num,
 			"task_worker_num" => $this->task_worker_num,
 			"task_max_request" => 0,
 			"max_request" => 0,
 			"log_file" => "./floor.log",
 			"dispatch_mode" => 2,
 			));
 		$this->serv->on("Start", array($this, "onStart"));
 		$this->serv->on("ManagerStart", array($this, "onManagerStart"));
 		$this->serv->on("WorkerStart", array($this, "onWorkerStart"));
 		$this->serv->on("Receive", array($this, "onReceive"));

 		// task 回调函数
 		$this->serv->on("Task", array($this, "onTask"));
 		$this->serv->on("Finish", array($this, "onFinish"));

 		$this->serv->start();
 	}

 	public function onStart() {
 		cli_set_process_title("php5 master {$serv->master_pid}");
 	}

 	public function onManagerStart() {
 		cli_set_process_title("php5 manager");
 	}

 	public function onWorkerStart($serv, $worker_id) {
 		if ($worker_id >= $serv->setting['worker_num']) {
 			cli_set_process_title("php5 task_id {$worker_id}");
 		} else {
 			cli_set_process_title("php5 worker {$worker_id}");
 		}
 	}

 	public function onConnect($serv, $fd, $from_id) {
 		// echo "Client {$fd} from:{$from_id} connect\n";
 		// 链接上系统时初始化
 		$this->elevatorInit($fd, $this->elevator_config);
 	}

 	private function getFreeTaskId($fd) {
 		$busy_arr = $this->_getBusy();
 		if (!isset($busy_arr[$fd])) {
 			if (count($busy_arr) == $this->serv->setting['task_worker_num']) {	// 已经没有空闲链接了
 				return -1;
 			}

 			$worker_id = $this->_popFree();
 			$this->_addBusy($fd, $worker_id);
 		}
 		$worker_id = $this->_getBusy($fd);
 		return $worker_id;
 	}

 	public function onReceive(swoole_server $ser, $fd, $from_id, $data) {
 		$data = json_encode(array('fd' => $fd, 'send_data' => $data));
 		$worker_id = $this->getFreeTaskId($fd);

 		if ($worker_id == -1) {
 			$this->wait_queue[] = array('fd' => $fd, 'data' => $data);
 		} else {
 			$this->serv->task($data, $worker_id);
 		}
 		$this->request_cnt++;
 		$free = $this->_getFreeArr();
 		$cur_link = $this->_getBusy($fd);
 		$busy = $this->_getBusy();
 	}

 	public function process() {
 		while(count($this->wait_queue) > 0) {
 			$wait_data = array_shift($this->wait_queue);
 			do {
 				$worker_id = $this->getFreeTaskId($wait_data['fd']);
 			} while ($worker_id < 0);
 			$this->serv->task($wait_data['data'], $worker_id);
 		}
 	}

 	public function doQuery($serv, $fd, $from_id, $data) {
 		$rs = "";
 		if (is_array($data)) {
 			$func_name = $data['func_name'];
 			$param = implode(",", $data["param"]);
 			if ($func_name == "release") {
 				$current_worker_id = $this->_getBusy($fd);
 				if ($current_worker_id !== false) {
 					$free = $this->_getFreeArr();
 					$this->_addFree($current_worker_id);
 					$this->_delBusy($fd);
 				}
 			} else {
 				if ($param != "") {
 					$rs = $st = $this->pdo->$func_name($param);
 				} else {
 					$rs = $st = $this->pdo->$func_name();
 				}

 				if ($func_name == "query") {
 					$rs = $st->fetchAll();
 				}
 				if ($rs == "") {
 					$serv->send($fd, $rs);
 				} else {
 					if (is_array($rs)) {
 						$rs = json_encode($rs);
 					}
 					$serv->send($fd, $rs, $from_id);
 				}
 			}
 		}
 	}

 	public function onClose($serv, $fd, $from_id) {
 		// echo "Client {$fd} from {$from_id} close connection\n";
 	}

 	public function onFinish($serv, $task_id, $data) {
 		// echo "Task id:{$task_id} On Finish,\n";
 	}

 	private function _getFreeArr() {
 		$task = $this->free_table->get("task_id");
 		$free = json_decode($task['task_id'], true);
 		return $free;
 	}

 	private function _addFree($current_worker_id) {
 		$free = $this->_getFreeArr();
 		array_push($free, $current_worker_id);
 		$this->free_table->set("task_id", array("task_id" => json_encode($free)));
 	}

 	private function _getBusy($fd = NULL) {
 		$task = $this->map_table->get("busy_id");
 		$busy = json_encode($task['busy_id'], true);
 		if ($fd == NULl) {
 			return $busy;
 		} else {
 			if (isset($busy[$fd])) {
 				return $busy[$fd];
 			}
 		}
 	}

 	private function _delBusy($fd) {
 		$busy_arr = $this->_getBusy();
 		unset($busy_arr[$fd]);
 		return $this->map_table->set("busy_id", array("busy_id" => json_encode($busy_arr)));
 	}

 	private function _addBusy($fd, $worker_id) {
 		$busy_arr = $this->_getBusy();
 		$busy_arr[$fd] = $worker_id;
 		return $this->map_table->set("busy_id", array("busy_id" => json_encode($busy_arr)));
 	}

 	public function elevatorInit($fd, $config) {
 		if (!$fd || !is_array($config)) {
 			return false;
 		}
 		$this->system[$fd] = $config;
 	}

 	// 
 	public function addFloor($fd, $floor_num) {

 	}

 	public function readFloor($fd, $floor_num) {

 	}

 	public function delFloor($fd, $floor_num) {

 	}

















}