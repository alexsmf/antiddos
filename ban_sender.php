<?php
include 'ad_config.php';

$fronts_set=$fronts_arr;//массив фронтендов, на которые будем рассылать баны

$wait_int=3;//будем ждать 3х5=15 секунд перед повтором рабочего цикла.

include('process_controller.php');
$pman = new Process_controller();
$pman->executable = "";
$pman->path = "";
$pman->debug=false;//если включить true будет выдавать много всяких echo
$pman->processes = 10;//сколько процессов разрешаем запускать параллельно
$pman->sleep_time = 2;//время ожидания между опросом состояния процессов

db_connect(true);

while(1){
	$dbip_arr=array();//загрузим в массив бан-IP адреса какие есть в базе

	$sql="SELECT INET_NTOA(ipn) as ip FROM `$dbtable`";
	$result=db_query($sql);
	if (!$result) die("Can't get data from database ".mysql_error()."\n");
	while($row=mysql_fetch_assoc($result)) {
		$ip=$row['ip'];
		$dbip_arr[]=$ip;
	}
	@mysql_free_result($result);

	$dbip_count=count($dbip_arr);
	if (!$dbip_count) {//в базе должен быть хоть 1 забаненный IP.
		//если в базе не найдено ни однго забаненного IP, значит что-то не так.
		echo "Error: no IP in database table $dbtable. Try again.\n";
		db_connect(true);//а раз что то пошло не так, просто начнём цикл заново.
		continue; 
	}
	//если в базе обнаружены забаненные IP-адреса
	echo "$dbip_count banned IP in database\n";

	//проверим состояние забанености на каждом фронтенде и если надо внесём изменения
	foreach($fronts_set as $front) {
		//echo "[$front]\n";
		//каков IP-адрес фронтенда?
		if(!isset($front_ip[$front])) { echo "Unknown frontend '$front'\n"; continue; }
		$fr_ip=$front_ip[$front];
		//придумаем имя файла со списком изменений в route-таблицу
		$ip_b_name='ip_b_'.$front.'.list';
		$ip_b_file='/var/tmp/'.$ip_b_name;
		//придумаем имя файла для дампа текущего состояния ip route list
		$ip_d_name='from_'.$front.'.routes';
		$ip_d_file='/var/tmp/'.$ip_d_name;
		//запомним нужные переменные в $tag, они будут передаваться процессам по цепочке
		$tag=compact('front','fr_ip','ip_b_file','ip_d_file');
		//добавляем задачу: сделать дамп ip route в файл $ip_d_file, и коллбэк на выход
		$pman->addScript(
			"ssh -i ~/.ssh/$front root@$fr_ip ".//удалённое исполнение команды
			escapeshellarg("ip route list>$ip_d_file"),//сама исполняемая команда
			30, 'ssh_route_dump_complete',$tag //по исполнению будет вызван коллбэк
		);
	}
	//запускаем все добавленные выше задания
	echo "Start...\n";
	$pman->exec();
	echo "Complete.\n";
	//подождем ($wait_int x 5) секунд
	echo "Wait ";
	for($i=$wait_int;$i>0;$i--) {
		echo shell_exec("sleep 5").'('.$i.')';
	}
	//хватит ждать, продолжнаем, поехали исполнять всё заново
	echo "Go\n";
	//конец рабочего цикла, начинаем всё заново
}
/**
 * Когда завершится задание ip route list > в файл, вызовется этот коллбэк.
 * его задача - если вызов завершился успешно, то поставить новое задание,
 * которое перекачает файл с удалённого фронтенда в локальную папку.
 */
function ssh_route_dump_complete($in_arr) {
	global $pman;
	$tag=$in_arr['tag'];extract($tag);
	//echo "ssh_route_dump_compete $front\n";
	if (!isset($in_arr['timeout'])) {
		if (!$in_arr['stderr']) {//если процесс завершился успешно и не выдал ошибок,
		$pman->addScript(//добавляем новое задание: скачать дамп-файл в локальную папку.
		"scp -c blowfish -C -B -i ~/.ssh/$front root@$fr_ip:$ip_d_file $ip_d_file",
		60, 'ssh_route_get_compete',$tag );//и добавляем коллбэк на продолжение.
		} else {//а если процесс вернул ошибку - сообщим о ней и не будем продолжать.
			echo "\nError from $front: ".$in_arr['stderr']."\n";
		}
	} else {//если процесс вообще завершился неудачно, то не будем пытаться продолжать
		echo "\nTimeOut dump ip routes on $front\n";
	}
}
/**
 * Когда файл с дампом ip route list скачался, проанализируем его и примем решения.
 * Если понадобится внести изменения - добавим соответствующее задание с коллбэком.
 */
function ssh_route_get_compete($in_arr) {
	global $pman;
	global $dbip_arr,$dbip_count;
	$tag=$in_arr['tag'];extract($tag);
	//echo "ssh_route_get_complete $front\n";
	if (!isset($in_arr['timeout'])) {//если процесс завершился вовремя
		if ($in_arr['stderr']) {//если процесс выдал ошибки
			//сообщим об ошибке и не будем пытаться продолжать.
			echo "Error on $front: ".$in_arr['stderr']."\n";
			return;
		}
		//если процесс завершился без ошибок - читаем полученный дамп ip route table
		$routelist=file_get_contents($ip_d_file);
		$ipt_arr=explode("\n",$routelist);
		//пробегаем все строки routes
		$banned_ip=array();
		$banned_net=array();
		$good_dump=false;//сделаем флаг для проверки, правильный ли нам файл прислался
		foreach($ipt_arr as $iptst) {
			if(!$iptst) continue;
			$st_arr=explode(" ",$iptst);
			if ($st_arr[1]=='dev') {
				$good_dump=true;//если есть такое, значит файл будем считать правильным
				if ($st_arr[2]=='lo') {//это признак забаненности
					$ip=$st_arr[0];//что именно забанено
					if (strpos($ip,'/')===false)//если нет такого знака, значит один IP
						$banned_ip[]=$ip;
					else
						$banned_net[]=$ip;//это если забанена целая подсеть
				}
			}
		}
		if (!$good_dump) {//если присланный файл не похож на дамп ip route list
			//сообщаем о наших подозрениях и выходим, отказываемся продолжать
			echo "Received file '$ip_d_file' have not ip route list from $front\n";
			return;
		}
		//если похоже что всё правильно, сопоставим список забаненых IP со списком базы
		$ipban_cnt=count($banned_ip);
		if ($ipban_cnt) {
			$diff=$dbip_count - $ipban_cnt;
			if (!$diff) echo 'all';
			else {
				if ($diff>0) echo '+';
				echo $diff.' ban, total '.$ipban_cnt;
			}
		} else echo "No";
		echo " IP banned on $front\n";
		if(count($banned_net)) {
			echo ' '.count($banned_net)." banned networks on $front\n";
		}
		$for_unban=array();//список айпишников для разбанивания на этом фронтенде
		$for_ban=array();//список айпишников для забанивания на этом фронтенде
		//теперь сравним то, что есть на фронтенде, с тем, что есть в базе
		//пробегаем айпишники в базе, смотрим каких не хватает в на фронтенде
		foreach($dbip_arr as $ip) {
			if (!in_array($ip,$banned_ip)) { //если айпишника нет среди забаненых на фронтенде
				$for_ban[]=$ip;
			}
		}
		//пробегаем айпишники на фронтенде, смотрим каких не хватает в базе
		foreach($banned_ip as $ip) {
			if(!in_array($ip,$dbip_arr)) {//если айпишник забанен, а в базе его нет
				$for_unban[]=$ip;
			}
		}
		if (count($for_ban)) echo count($for_ban)." IP must be banned on $front\n";
		if (count($for_unban)) echo count($for_unban)." IP must be UNbanned on $front\n";

		if (count($for_ban)||count($for_unban)) {
			$ip_b_content='';//"#!/bin/bash\n\n";
			if (count($for_ban)) {
				#$ip_b_content.="#Banning:\n";
				foreach($for_ban as $ip) {
					if (!$ip) continue;
					if ($ip=='0.0.0.0') continue;//чисто на всякий случай
					#$ip_b_content.="ip route add $ip/32 dev lo\n";
					$ip_b_content.="route add $ip/32 dev lo\n";
				}
			}
			if (count($for_unban)) {
				#$ip_b_content.="\n#UnBanning:\n";
				foreach($for_unban as $ip) {
					if (!$ip) continue;
					if ($ip=='0.0.0.0') continue;//чисто на всякий случай
					#$ip_b_content.="ip route del $ip/32\n";
					$ip_b_content.="route del $ip/32\n";
				}
			}
			#$ip_b_content.="\n#END\n";
			$f=fopen($ip_b_file,'w');
			if (!$f) die("Error opening $ip_b_content");
				fwrite($f,$ip_b_content);
			fclose($f);
			#chmod($ip_b_file,0755);

			$pman->addScript(
				"scp -B -i ~/.ssh/$front $ip_b_file root@$fr_ip:$ip_b_file",
				60,'scp_banlist_send_complete',$tag
			);
		}
	} else {
		echo "\nTimeOut get 'ip route list' from $front\n";
	}
}
function scp_banlist_send_complete($in_arr) {
	global $pman;
	$tag=$in_arr['tag'];
	extract($tag);
	if (!isset($in_arr['timeout'])) {
		echo "SCP: '$front'($fr_ip) changes was sent.\n";
		$pman->addScript(
			"ssh -i ~/.ssh/$front root@$fr_ip ip -b $ip_b_file", 10,
			'ssh_banlist_execute_complete',$tag
		);
	} else {
		echo "Sending '$ip_b_file' by scp to '$front'($fr_ip) timeout.\n";
	}
}
function ssh_banlist_execute_complete($in_arr){
	extract($in_arr['tag']);
	if (!isset($in_arr['timeout'])) {
		if ($in_arr['stderr']) {
			echo "ERROR SSH '$ip_b_file' on '$front'($fr_ip):\n".$in_arr['stderr'];
		} else {
			echo "OK SSH '$front'($fr_ip) changes complete.\n";
		}
	} else {
		echo "SSH execution '$ip_b_file' on '$front'($fr_ip) timeout.\n";
	}
}
