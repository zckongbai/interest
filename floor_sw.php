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

		// 任务队列
		$this->elevator_table = new swoole_table(1024);
		$this->elevator_table->column("fd", swoole_table::TYPE_INT);
		$this->elevator_table->column("status", swoole_table::TYPE_STRING, 64);
		$this->elevator_table->column("now", swoole_table::TYPE_INT);
		$this->elevator_table->column("go", swoole_table::TYPE_STRING, 1000);
		$this->elevator_table->create();

 	} 

 	public function run() {
 		// $this->serv = new swoole_server("127.0.0.1", $this->port);
 		$this->serv = new swoole_websocket_server("127.0.0.1", $this->port);
 		$this->serv->set(array(
 			"worker_num" => $this->worker_num,
 			"max_request" => 0,
 			"log_file" => "./floor.log",
 			"dispatch_mode" => 2,
 			));
 		$this->serv->on("Start", array($this, "onStart"));
 		$this->serv->on("ManagerStart", array($this, "onManagerStart"));
 		$this->serv->on("WorkerStart", array($this, "onWorkerStart"));
 		$this->serv->on("Connect", array($this, "onConnect"));
 		$this->serv->on("Receive", array($this, "onReceive"));

 		$this->serv->start();
 	}

 	public function onStart() {
 		echo "sys start \n";
 	}

 	public function onManagerStart() {
 	}

 	public function onWorkerStart($serv, $worker_id) {
 	}

 	public function onConnect($serv, $fd, $from_id) {
 		echo "Client {$fd} from:{$from_id} connect\n";
 		// 链接上系统时初始化
 		if (!$this->elevatorInit($fd, $this->elevator_config)) {
 			return false;
 		}
 		$tick_id = swoole_timer_tick(1000, array($this, "go"), $fd);
 		$this->tick_id = $tick_id;
 	}

	public function onReceive(swoole_server $ser, $fd, $from_id, $data) {
 		echo "get message from Client {$fd} \n";
 		$data = json_decode($data, true);
 		$this->addFloor($fd, $data['now'], $data['want'], $data['status']);
 		$tick_id = swoole_timer_tick(1000, array($this, "go"), $fd);
 		$this->tick_id = $tick_id;
 	}

 	public function elevatorInit($fd, $config) {
 		if (!$fd || !is_array($config)) {
 			return false;
 		}
 		// 任务队列初始化
 		$res = $this->elevator_table->set($fd, array("fd"=>$fd, "status"=>$config['status'], "now"=>$config['now'], "go"=>json_encode(array())));
 	}

 	// 添加任务
 	/**
 	*	$floor_now 当前楼层
 	*	$floor_want 要去楼层
 	*	$status 升降 up | down | click
 	*
 	*/
 	public function addFloor($fd, $floor_now, $floor_want="", $status="") {
 		$elevator = $this->elevator_table->get($fd);
 		if (!$elevator) {
 			return false;
 		}
 		// 如果是空闲
 		if ($elevator['status'] == "free") {
 			// 门外按的上下
 			if (empty($floor_want) && in_array($status, array("up", "down"))) {
 				$elevator['status'] = $floor_now >= $elevator['now'] ? "up" : "down";
 			}
 		}

 		$go = json_decode($elevator['go'], true);
		$want_info = array('floor_now'=>$floor_now, 'floor_want'=>$floor_want, 'status'=>$status);
		$go[] = $want_info;
		$res = $this->elevator_table->set($fd, array("fd"=>$fd, "status"=>$elevator["status"], "now"=>$elevator["now"], "go"=>json_encode($go)));
		// 按钮置亮
		$msg = "{$floor_now} want ";
		if ($floor_want) {
			$msg .= $floor_want;
		}else {
			$msg .= $status;
		}
 		$this->serv->send($fd, json_encode(array("msg" => $msg)));
 		echo $msg . "\n";
   	}
 	/**
 	* Int $floor_now = 1,2,3..
 	* Int $floor_want = 1,2,3 || ""
 	* String $status = up || down || click
 	*/
 	public function openDoor($fd, $floor_now, $floor_want, $status="click") {
 		$this->addFloor($fd, $floor_now, $floor_want, $status); 
 	}

 	public function closeDoor($fd, $floor_now, $floor_want, $status="click") {
 		$this->delFloor($fd, $floor_now, $floor_want, $status);
 	}

 	public function readFloor($fd, $floor_num) {

 	}

 	public function delFloor($fd, $floor_now, $floor_want="", $status="") {
 		$elevator = $this->elevator_table->get($fd);
 		if (!$elevator) {
 			return false;
 		}
 		$go = json_decode($elevator['go'], true);
 		$del_floor = array('floor_now'=>$floor_now, 'floor_want'=>$floor_want, 'status'=>$status);
 		if (false !== ($index = array_search($del_floor, $go))) {
 			unset($go[$index]);
 		}
		$this->elevator_table->set($fd, array("fd"=>$fd, "status"=>$elevator['status'], "now"=>$elevator['now'], "go"=>json_encode($go)));
 		$msg = "{$floor_now} want ";
 		if ($floor_want){
 			$msg .= $floor_want . " is del";
 		} else {
 			$msg .= $status . " is del";
 		}
 		$this->serv->send($fd, json_encode(array("msg" => $msg)));
 	}

 	public function statusChange($fd, $status="", $now="") {
 		$elevator = $this->elevator_table->get($fd);
 		if (!$elevator) {
 			return false;
 		}
 		if ($status) {
 			$elevator['status'] = $status;
 		}
 		if ($now) {
 			$elevator['now'] = $now;
 		}
 		$this->elevator_table->set($fd, $elevator);
 	}

 	public function go($fd) {
 		echo " go is run| fd = $fd \n";
 		$elevator = $this->elevator_table->get($fd);
 		if (!$elevator) {
 			echo "elevator get false\n";
 			// 清除任务, 更改状态
 			swoole_timer_clear($this->tick_id);
 			$this->statusChange($fd, "free", $elevator["now"]);
 			return false;
 		}
 		$go = json_decode($elevator['go'], true);
 		if (empty($go)) {
 			echo " go is null\n";
 			// 清除任务, 更改状态
 			swoole_timer_clear($this->tick_id);
 			$this->statusChange($fd, "free", $elevator["now"]);
 			return false;
 		}

 		// 本次任务
 		$this_time = array();
 		// 要去的楼层
 		$want = "";
 		// 统计上下任务数量
 		$some_num = $diff_num = 0;

 		foreach ($go as $key => $value) {
 			if ($value['status'] == $elevator['status']) {
 				// 同方向任务数量
 				$some_num ++;
 				// 分上下两种情况
 				if ($elevator['status'] == "up" && $value['floor_now'] >= $elevator['now'])  {
 					// 先分配一个 
 					if (empty($want)) {
 						$want = $value['floor_now'];
 						$this_time = $value;
 					}
 					// 找到最近的楼层
					if (empty($value['floor_want'])) {
						if ($value['floor_now'] < $want) {
							$want = $value['floor_now'];
 							$this_time = $value;
						}
					} else if ($value['floor_want'] < $want) {
						$want = $value['floor_want'];
						$this_time = $value;
					}

 				}
 				if ($elevator['status'] == "down" && $value['floor_now'] <= $elevator['now']) {
 					// 先分配一个 
 					if (empty($want)) {
 						$want = $value['floor_now'];
 						$this_time = $value;
 					}
 					// 找到最近的楼层
					if (empty($value['floor_want'])) {
						if ($value['floor_now'] > $want) {
							$want = $value['floor_now'];
 							$this_time = $value;
						}
					} else if ($value['floor_want'] > $want) {
						$want = $value['floor_want'];
						$this_time = $value;
					}
 				}
 			} else {
 				// 不同方向任务数量
 				$diff_num ++;
 			}

 		}
 		if ($this_time){
	 		// 任务执行
	 		if ($this->working($fd, $elevator['now'], $want)) {
	 			// 从任务队列删除任务
	 			$this->delFloor($fd, $this_time['floor_now'], $this_time['floor_want'], $this_time['status']);
	 			// 更改电梯状态
	 			if ($some_num > 1) {
	 				$status = $elevator['status'];
	 			} else if ($diff_num > 0) {
					$status = $elevator['status'] == "up" ? "down" : "up";
	 			} else {
	 				$status = "free";
	 			}
	 			$this->statusChange($fd, $status, $want);
	 		}
	 	} else {
	 		// echo " this_time is null\n";
	 		var_dump($elevator,$go);
	 	}
 		return true;
 	}

 	// 模拟执行一个任务
 	public function working($fd, $now, $want) {
 		$msg = "{$fd} from {$now} to {$want}";
 		$this->serv->send($fd, json_encode(array("msg" => $msg)));
 		sleep(5);
 		$msg = "{$fd} is on {$want}";
 		$this->serv->send($fd, json_encode(array("msg" => $msg)));
 		return true;
 	}
}


require_once("./config.php");
$sys = new Floor($config);
$sys->run();



