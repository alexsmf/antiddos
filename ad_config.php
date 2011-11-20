<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

//базовый URL сайта
$base_url='forum.omsk.com';

//Настройки базы данных
$dbhost = '127.0.0.1:3306'; $dbuser='antiddos'; $dbpass='*********'; $dbname=$dbuser;
$dbtable='ip_banned';

//список фронтендов и все необходимые данные про них
$frontends_all=array();
front_add('78.24.217.10' ,'firstvds','8181');
....
front_add('83.69.226.254','hostline','8787',true);//true надо на последнем

//дальше идут всякие функции, конфигурировать не надо
function front_add($ip,$front,$port,$recalc=false){
	global $frontends_all;
	$frontends_all[$ip]=array($front,$port);
	if ($recalc) fronts_recalc();
}
function front_get_port($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$row=$frontends_all[$front_ip[$front]];
		return $row[1]; //its a port
	}
}
function fronts_recalc() {
	global $frontends_all;
	global $frontend,$front_ip,$fronts,$fronts_arr;
	$frontend=array();//массив $frontend[$ip]='имя фронтенда'
	$front_ip=array();//массив $front_ip[имя фронтенда]=$ip
	$fronts='';//список фронтендов через запятую: firstvds,truevds,vdscom,inferno
	$fronts_arr=array();//список фронтендов в виде массива [0]=>firstvds,[1]=>...
	foreach($frontends_all as $ip=>$row) {
		$front=$row[0];
		//$froun_uniq_port=$row[1];

		$frontend[$ip]=$front;
		
		$front_ip[$front]=$ip;

		if ($fronts) $fronts.=',';
		$fronts.=$front;

		$fronts_arr[]=$front;
	}
}
/**
 * Устанавливает соединение с базой данных на основании имеющихся установок.
 * если параметр $waiting задан true, то зацикливается до тех пор пока не соединится.
 * если $waiting=false, тогда при неудачной попытке соединения выходит с возвратом false
 * если $only_check_link=true то просто возвращает текущее состояние $dblink
 */
function db_connect($waiting=false,$only_check_link=false) {
	global $dbhost, $dbuser,$dbpass, $dbname;
	static $dblink=false;
	if ($only_check_link) return $dblink;
	//если соединение с базой числится уже установленным, то закроем его.
	if ($dblink) { @mysql_close($dblink); $dblink=false; }
	//цикл "пока соедиение не установлено"
	while (!$dblink) {
		echo "Connecting to database: $dbhost ...";
		//пытаемся подключиться к базе данных
		$dblink = @mysql_connect($dbhost, $dbuser, $dbpass);
		if ($dblink === false) {
			$dberrtxt="Could not connect : " . mysql_error();
		} else {
			//если подключение успешно - пытаемся выбрать базу данных
			if (mysql_select_db($dbname)) break;//если успешно - выходим
			else {//если выбрать базу данных не удалось, повторим цикл заново
				$dberrtxt="Could not select database '$dbname'\n";
				@mysql_close($dblink); $dblink=false;
			}
		}
		echo $dberrtxt."\n";
		if (!$waiting) break;//если ожидание не задано - выходим
		sleep(3);//ждем 3 секунды перед следующей попыткой соединения
	}
	if ($dblink !== false) echo "DB $dbname connected.\n";
	return $dblink;
}
/**
 * Функция db_query($sql) - замена для mysql_query($sql)
 * В отличие от mysql_query пытается поддерживать соединение с базой данных,
 * пытается восстанавливать соединение с базойданных, если оно пропадает.
 */
function db_query($sql) {
	//проверим наличие соединения с базой данных
	if (db_connect(false,true)===false) { //если соединения нет,
		$dblink=db_connect(true); //попытаемся установить соединение
		if (!$dblink) return false; //если соединиться не удалось - выходим с false
	}
	//если соединение с базой данных числится существующим, пробуем послать запрос
	while (false===($result=@mysql_query($sql))) {
		//сюда мы попадаем если запрос не удался (вернул false)
		echo "err: ".mysql_error()."\n"; //выводим сообщение об ошибке
		//если ошибка >2000 (то есть проблемы с сервером) то реконнектимся,
		//если ошибка другая - выходим и возвращаем false
		if (mysql_errno()>2000) db_connect(true); else break;
	}
	return $result;
}