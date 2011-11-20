<?php
class Process_controller {
	public $executable = "php -q";//������ ������� (��������, ��� php-��������)
	public $root = ""; //����, ������� ����� �������� ����� ������ �������
	public $scripts = array();//������ ��������� ��������
	public $processesRunning = 0;//���������� ��������� ���������
	public $processes = 3;//������������ ���������� ������������� ���������
	public $running = array();//������ ������������� ��������� (������� ������ Process)
	public $sleep_time = 2;//�������� � ����� ����� ����� ��������� ��������� ���������
	public $debug=false; //�������� �� ��������� � ����� ���������

	function addScript($script, $max_execution_time = 60,$callback='',$tag=0) {
		//���������� ����� ������ � ������� �� ����������
		$this->scripts[] = array(
			"script_name" => $script,//��� �������
			"max_execution_time" => $max_execution_time,//����-����� (� ��������)
			"callback" => $callback,//������� ���������� �� ����������
			"tag"=>$tag,//�������������� ���������, ������ ������������ callback-�������
		);
	}
	function exec() {
		//����� ��������� � ����� �� ��� ���, ���� ��� ������ ��� ��� ����� �� ����������.
		$i = 0;
		while(1) {
			// Fill up the slots
			while (
				($this->processesRunning<$this->processes)
				and
				($i<count($this->scripts))
			) {
				if ($this->debug) echo "Start: ".$this->scripts[$i]["script_name"]."\n";
				$this->running[] = new Process(
					$this->executable,
					$this->root,
					$this->scripts[$i]["script_name"],
					$this->scripts[$i]["max_execution_time"],
					$this->scripts[$i]["callback"],
					$this->scripts[$i]["tag"]
				);
				$this->processesRunning++;
				$i++;
			}
			// Check if done
			if (
				($this->processesRunning==0)
				and
				($i>=count($this->scripts))
			) break;
			else if ($this->debug) echo "Running: ".$this->processesRunning.
							" (".(count($this->scripts)-$i)." in queue)\n";

			sleep($this->sleep_time);//������ �������� ������ ��� ��������� ���������

			// check what is done
			foreach ($this->running as $key => $val) {
				if (!$val->isRunning() or $val->isOverExecuted()) {
					$callback=$val->callback;
					$script=$val->script;
					$tag=$val->tag;
					$proc_closed=true;
					if (!$val->isRunning()) {//���� ������� ��� ����������
						if ($callback) {//���� ���� ������� ������� ���
							//��������� �������������� ��� ��� ������� �����
							$stdout=stream_get_contents($val->pipes[1]);
								fclose($val->pipes[1]);
							$stderr=stream_get_contents($val->pipes[2]);
								fclose($val->pipes[2]);
							//�������� ������� � ������� ��� ���������
							call_user_func($callback,compact(
								'script','tag','stdout','stderr'));
						} else {
							//���� �������� ���, �� � ������ ������ ������.
							if ($this->debug)
								echo "Done process: '".$val->script."' (no callback)\n";
						}
					} else {//���� ������� ��������, � ������� �� ����������
						if ($callback) {//���� ���� �������-�������, ������� �
							if (call_user_func($callback,array(
								'script'=>$script,
								'tag'=>$tag,
								'timeout'=>(mktime()-$val->start_time),
								'process'=>$val->resource,
							))) {//���� ������� ������� �� ������, �� ������� �������
								$proc_closed=false;//������ ���� ����� �� ������� �������
								if ($this->debug) echo "Process '".$val->script."' continue.\n";
							}
						}
						if ($proc_closed) {//������� ������� ���� ���� ���� ����
							proc_terminate($val->resource);
							if ($this->debug) echo "Killed process: '".$val->script."'\n";
						}
					}
					if ($proc_closed) {//���� ������� �������� - ������� � �� ������
						proc_close($val->resource);
						$val->callback='';//����� ��� unset ��������� ������� �� __destroy
						unset($this->running[$key]);
						$this->processesRunning--;
					}
				}
			}
		}
		$this->scripts=array();//������� ������ ��������, ��� ��� ���������
	}
}

class Process {
	public $resource;
	public $pipes;
	public $script;
	public $callback;
	public $tag;
	public $max_execution_time;
	public $start_time;

	function __destroy() {
		if ($this->callback){
			$stdout=stream_get_contents($this->pipes[1]); fclose($this->pipes[1]);
			$stderr=stream_get_contents($this->pipes[2]); fclose($this->pipes[2]);
			call_user_func($this->callback,array(
				'script'=>$this->script,
				'tag'=>$this->tag,
				'exectime'=>(mktime()-$this->start_time),
				'process'=>$this->resource,
			));
		}
	}
	function __construct(&$executable, &$root,
		$script, $max_execution_time,
		$callback='', $tag=false, $input='') {
		$this->script = $script;
		$this->tag = $tag;
		$this->callback = $callback;
		if ($callback && !function_exists($callback))
			die("Undefined callback function '$callback' for '$script'\n");
		$this->max_execution_time = $max_execution_time;

		$this->resource = proc_open(
			$executable." ".$root.$this->script,
			array(
				0 => array('pipe', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w')
			),
			$this->pipes,
			null, $_ENV
		);
		$this->start_time = mktime();
	}
	// is still running?
	function isRunning() {
		//@@@ � php ���� ������ ���:
		//@@@ ���� ������� ����� ����� 64�, ���� ���� ������ ����� TRUE
		//@@@ ������� ���� ���� ������� ����� ������ - �� ����� ����� > ����� � ����
		$status = proc_get_status($this->resource);
		return $status["running"];
	}
	// long execution time, proccess is going to be killer
	function isOverExecuted() {
		if ($this->start_time+$this->max_execution_time<mktime()) return true;
		else return false;
	}
}