<?php
/**
 * Крутится в бесконечном цикле и анализирует появляющиеся строки в логе вебсервера
 * Каждая новая строка разбивается на параметры и передаётся функции ddos_detector,
 * задача которой - вернуть true если по результатам анализа решено забанить,
 * либо вернуть false, если по результатам анализа запроса решено не забанивать.
 */
include 'ad_config.php';
//Добавим опцинальные модули, если хочется.
include 'module_frontmon.php';//мониторинг фронтендов
include 'module_lasthits.php';//мониторинг последних вызовов
include 'module_visualizer.php';//реалтайм-визуализатор запросов
/**
 * Вообще, на входе нам нужны:
 * $dbtable='ip_banned'; //имя таблицы с бан-списком (в ней ipn = INET_ATON('$ip') )
 * Список фронтедов, в таком формате:
 * $frontend=array('IP1'=>'name1','IP2'=>'name2', ... )
 */
db_connect(true);//подключимся к базе данных, а то вдруг она недоступна

init();//выполним первичную инициализацию всевозможных счётчиков (см. функцию)

/**
 * Рабочий цикл получает реалтайм данные из лог-файла apache2
 * и анализирует каждую полученную строку вызывая функцию analyze($st)
 */
$logfile='/var/log/apache2/access.log';
/**
 * Формат лог-файла таков: (в формате apache2.conf)
 * LogFormat "%>s %t %a \"%r\" %{X-Srv-IP}i %{X-Srv-X}i %{X-Geo-C}i" combined
 * Переменные X-Srv-IP, X-Srv-X, X-Geo-C означают следующее:
 *
 * X-Srv-IP - ip-адрес фронтенда, принявшего запрос.
 * В конфиге nginx он прописывается так:
 * 		proxy_set_header X-Srv-IP $server_addr;
 *      Не забываем, чтоэтот адрес должен быть прописан также в rpaf.conf !
 * 
 * X-Srv-X - протокол, по которому обратился клиент (http либо https)
 * В конфиге nginx на фронтенде прописывается так:
 * 		proxy_set_header X-Srv-X $scheme;
 * 
 * X-Geo-C - трехбуквенный код страны (на фронтенде должен быть влючен GeoIP)
 * В конфиге nginx прописывается так:
 *			proxy_set_header X-Geo-C $geoip_country_code3;
 * для включения GeoIP на nginx в секциию http надо добавить:
 * 			geoip_country  /usr/share/GeoIP/GeoIP.dat;
 *
 * Пример строки из лог-файла:
 * 304 [16/Nov/2011:18:52:00 +0300] 188.232.61.250 "GET / HTTP/1.0" 78.24.217.10 https RUS
 */
while(1) {//читаем лог в режиме реалтайм и анализируем каждую новую строку
	$handle = popen("tail -F $logfile 2>&1", 'r');
	while(!feof($handle))
		analyze(fgets($handle));
	pclose($handle);
}
/**
 * Здесь описывается первичная инициализация массивов и счетчиков
 */
function init() {
	 //список всех встреченных в логе ip-адресов в формате $iparr[$ip]=time()
	global $ip_time_all;//где time() - время последнего хита с этого IP
	//список последнего скриптового вызова с этого ip
	global $ip_time_scr;//запоминает время последнего скриптового вызова с этого IP
	//список общего количества вызовов с этого $ip_cnt_all[$ip]=cnt
	global $ip_cnt_all;//просто тупой счётчик хитов по каждоему IP-адресу
	//кол-во вызовов скриптовых страниц с интервалом менее 3 секунд
	global $ip_cnt_scr_short;//считаем корткие вызововы скриптов (не забывая про искалки!)
	//кол-во вызовов, которые мы считаем вероятным признаком враждебных действий
	global $ip_cnt_very_bad;
	// *** Мониторинг состояния фронтендов ***
	//время последнего запроса по каждому фронтенду
	global $front_last_time; //$front_in_sec[front]=time();
	//флаги о том, что с фронтенда слишком долго нет хитов, для мониторинга состояния
	global $front_alarm_flag;//либо 0, либо время задержки в секундах
	//счёт количества хитов по каждому фронтенду
	global $front_hit_cnt;//просто для мониторинга состояния будем их считать
	// *** Мониторинг последних запросов ***
	global $last_hits_arr; //массив последних хитов
	//инициализируем все вышеперечисленные массивы пусыми
	$ip_time_all=
	$ip_cnt_all=
	$ip_time_scr=
	$ip_cnt_scr_short=
	$ip_cnt_very_bad=
	$front_last_time=
	$front_alarm_flag=
	$front_hit_cnt=
	$last_hits_arr=
	array();

	global $frontend;//запишем нули в массивы мониторинга фронтендов,
	// (просто чтобы потом не проверять isset-ом существование)
	foreach($frontend as $front) {
		$front_alarm_flag[$front]=0;
		$front_last_time[$front]=0;
		$front_hit_cnt[$front]=0;
	}
}
/**
 * Каждая новая строка из лога обрабатывается этой функцией
 */
function analyze($st) {
	global $dbtable,$frontend;
	global $ip_time_all,$ip_time_scr;
	global $ip_cnt_all,$ip_cnt_scr_short,$ip_cnt_very_bad;
	global $f_htaccess;// прописан в конфиге
	$tm=time();
	//разбиваем входную строку на интересующие нас параметры
	$parr=explode(" ",$st); 

	$ans=$parr[0];//код ответа сервера (200, 403 и т.д)
	$tmh=$parr[1];//начало времени без часового пояса
	$ip=$parr[3]; //IP клиента
	$uri=chop($parr[5]);//запрошенный QUERY_STRING в формате /abc.php
	$srv_ip=chop($parr[7]);//IP-адрес фронтенда, принявшего запрос
	$scheme=chop($parr[8]); //протокол с фронтенда http или https
	$country3=chop($parr[9]); //страна по GeoIP
	//выясним имя фронтенда по массиву $frontend, либо именем будем считать srv_ip
	if (isset($frontend[$srv_ip])) $front=$frontend[$srv_ip]; else $front=$srv_ip;
	//разобьём $uri на $url и $par по знаку вопроса, если он там есть
	$i=strpos($uri,'?');
	if ($i===false) {
		$url=$uri;
		$par='';
	} else {
		$par=substr($uri,$i+1);
		$url=substr($uri,0,$i);
	}
	//проверим, вызван ли скриптовый url или статичный (картинки и т.п)
	if (substr($url,-4)=='.php' || substr($url,-1)=='/') {
			$is_script=true;
	} else	$is_script=false;

	$i=strpos($tmh,':');
	$tmh=substr($tmh,$i+1);

	//если включен модуль мониторинга последних вызовов
	if (function_exists('last_hit_push'))
		last_hit_push($ans,$tmh,$ip,$scheme,$url,$par,$country3,$front);

	//Встречался нам раньше этот IP, или он новый?
	if (isset($ip_time_all[$ip])) {
		//если ip-адрес уже встречался, подсчитаем о нём всякие параметры
		$diff_all=$tm-$ip_time_all[$ip];//сколько секунд прошло с предыдущего вызова с этого ip?
		$ip_cnt_all[$ip]++;//считаем общее количество хитов с этого IP
		if ($is_script) {
			//если вызван скрипт - посчитаем, время с последнего вызова скрипта
			if (isset($ip_time_scr[$ip])) {
				$diff_scr=$tm-$ip_time_scr[$ip];
				//если со времени прошлого скрипто-вызова прошло менее 3 секунд
				if ($diff_scr<3) {
					//увеличим счётчик слишком быстрых скрипто-вызовов
					$ip_cnt_scr_short[$ip]++;
				}
				if ($diff_scr>30) {
					//если с предыдущего скрипто-вызова прошло более 30 секунд
					if($ip_cnt_scr_short[$ip]>0) $ip_cnt_scr_short[$ip]--;
				}
			}
		}
	} else {
		//Если этот ip-адрес нам встретился впервые
		$diff_all=-1; //времени с последнего вызова неизвестно сколько
		$ip_cnt_all[$ip]=1;//общее кол-во вызовов с этого IP =1
		$ip_cnt_scr_short[$ip]=0;//количество быстрых скрипто-вызовов скриптов =0
	}
	$ip_time_all[$ip]=$tm;//запомним время последнего вызова для данного ip-адреса
	if ($is_script) $ip_time_scr[$ip]=$tm;//запомним время последнего скрипто-вызова

	//Если включн модуль, визуализируем запрос в удобном виде
	if (function_exists('visualizer')) {
		$scv=visualizer(compact('is_script','url','par','scheme','diff_all'));
		echo $scv;
		if ($scv=='6') return;//если вызов протокола ipv6 не будем анализиовать дальше
	}

	//вот здесь-то мы и должны решить, банить этот IP или не банить.
	$must_be_banned = ddos_detector(compact(
		'tm','is_script',
		'ans','ip','uri','url','par','scheme','country3'
		));

	if ($must_be_banned) {//если айпишник надлежит забанить
		if ($ans=='200') {//если наш ответ был 200, добавим IP в бан на .htaccess
			// должен быть прописан в конфиге $f_htaccess='/var/www/.htaccess';
			$f=fopen($f_htaccess,'a');
			if ($f) {
				fwrite($f,"\nDeny from $ip");
				fclose($f);
				echo "\n***";
			} else echo "Can't open $f_htaccess";
		} else echo "-";

		//проверим, есть ли этот IP в базе забаненых
		$sql="SELECT * FROM `$dbtable` WHERE ipn=INET_ATON('$ip') LIMIT 1";
		$result=db_query($sql);
		if (!$result) echo "Can't get data from database ".mysql_error();
		else {
			//это сугубо для визуализации
			if ($is_script) {
				$scr='('.$ip_cnt_scr_short[$ip].')';
			} else $scr='';

			if (mysql_num_rows($result)) {
				//если этот IP уже есть в бан-базе
				$row=mysql_fetch_assoc($result);
				mysql_free_result($result);
				//апдейтим этот IP, увеличим его счётчик, обновим время
				$sql='UPDATE `'.$dbtable.'` SET hitcnt=hitcnt+1';
				if (!$row['country3'] && $country3)
					$sql.=', country3="'.$country3.'"';
				$sql.=' WHERE ipn=INET_ATON("'.$ip.'")';
				mysql_query($sql);
				//визуализируем событие, что к нам прорвался забаненый IP-адрес
				echo "\n!$ans:".$ip.'='.$ip_cnt_all[$ip].'['.$url."]$par $scr $row $front $scheme $country3\n";
			} else {
				//визуализируем событие, что мы решили забанить новый IP-адрес
				echo "\n+$ans:".$ip.'='.$ip_cnt_all[$ip].'['.$url."]$par $scr $front $scheme $country3\n";
				//забиваем в таблицу новый вражеский IP-адрес, если его там не было
				$sql='INSERT IGNORE INTO `'.$dbtable.'` SET ipn=INET_ATON("'.$ip.'"), country3="'.$country3.'", first_time=NOW()';
				mysql_query($sql);
			}
		}
	}
	//если подключен модуль мониторинга фронтендов
	if (function_exists('hit_front')) hit_front($front,$srv_ip,$tm);
}
/**
 * Задача этой функции - решить, надо ли забанить IP анализируемого запроса.
 */
function ddos_detector($in_arr) {
	global $ip_time_all,$ip_time_scr;
	global $ip_cnt_all,$ip_cnt_scr_short,$ip_cnt_very_bad;

	extract($in_arr);

	$must_be_banned=false; //презумпция невиновности

	//разумеется, конкретные параметры детектирования подстраивать под конкретный ддос.

	//есть ли в запросе паттерны, характереные для вражеских вызовов?
	$its_very_bad=(strpos($par,'t=202371')!==false);

	$badcnt=0; //подсчитем, сколько раз на этм IP детектились плохие паттерны
	if ($its_very_bad) {
		if (isset($ip_cnt_very_bad[$ip])) $ip_cnt_very_bad[$ip]++;
			else $ip_cnt_very_bad[$ip]=1;
		$badcnt=$ip_cnt_very_bad[$ip];
		echo '{'.$badcnt.'}';
	}

	//не пора его ли забанить ? пороги срабатывания...
	if ( ( $badcnt>2 && $ip_cnt_scr_short[$ip]>2 )
	  || ( $badcnt>10 )
	) $must_be_banned=true;

	return $must_be_banned;
}
