<?php
#(c) Vadim Pavlov 2018-2020
#ioc2rpz GUI vars

const DB="sqlite"; //for a single user it is Ok
const DBFile="io2cfg/io2db.sqlite";
const DBCreateIfNotExists=true;

const ioc2rpzConf="io2cfg";

const dig="/usr/bin/dig +tcp";
#const dig="/usr/bin/kdig +tls";

const io2mgmt="rest"; #ioc2rpz management interface: rest or dns
const io2mgmt_verifyssl=false; #Verify SSL. If there is a self signed certificate - set to false.
const rest_mgmt_port=8443;

$io2ver=2019073101;

function filterIntArr($array){
  $result = [];
  foreach ($array as $a) {if (is_numeric($a)) $result[]=$a;};
  return $result;
};


function getGroupsId($array){
  $result = [];
  foreach ($array as $a) {if (preg_match('/^gr_(\d+)$/',$a,$m)) $result[]=$m[1];};
  return $result;
};

function checkDB(){
  
};


function DB_open() 
{ 
  switch (DB){
    case "sqlite":
      $db = new SQLite3(DBFile);
      $db->busyTimeout(5000);
      $db->exec('PRAGMA journal_mode = wal;'); //PRAGMA foreign_keys = ON;
    break;
  }
  return $db; 
}

function DB_close($db) 
{ 
  switch (DB){
    case "sqlite":
      $db->close();
    break;
  }
}

function DB_select($db,$sql){
  switch (DB){
    case "sqlite":
      $result=$db->query($sql);
    break;
  }
  return $result;
};

function DB_escape($db,$text){
  switch (DB){
    case "sqlite":
      $result=$db->escapeString($text);
    break;
  }
  return $result;
};

function DB_boolval($val){
  switch (DB){
    case "sqlite":
      $result=$val=="1"?1:0;
    break;
  }
  return $result;
};



function DB_selectArray($db,$sql){
  switch (DB){
    case "sqlite":
			#error_log("$sql\n");
      $data=[];
      $result=$db->query($sql);
      while ($row=$result->fetchArray(SQLITE3_ASSOC)){
        $data[]=$row;
      };
    break;
  }
  return $data;
};


function DB_fetchArray($result){
  switch (DB){
    case "sqlite":
      $data=$result->fetchArray(SQLITE3_ASSOC);
    break;
  }
  return $data;
};

function DB_execute($db,$sql){
  switch (DB){
    case "sqlite":
      $result=$db->exec($sql);
    break;
  }
  return $result;
};

function genConfig($db,$USERID,$SrvId){
  //srv
  $row=DB_selectArray($db,"select * from servers where user_id=$USERID and rowid=$SrvId;")[0];
  $cfg="% ioc2rpz server ${row['name']} config generated by ioc2rpz.gui at ".date("Y-m-d H:i:s")."\n"; 
  $cfg.="\n% srv record: ns, email, [tkeys], [mgmt]\n";
  $response['filename']=$row['URL']?$row['URL']:"${row['name']}.conf";
  $subres=DB_selectArray($db,"select name from servers_tsig left join tkeys on tkeys.rowid=servers_tsig.tsig_id where servers_tsig.user_id=$USERID and servers_tsig.server_id=$SrvId");  
  $subres1=DB_selectArray($db,"select mgmt_ip from mgmt_ips where mgmt_ips.user_id=$USERID and mgmt_ips.server_id=$SrvId;");

  $subres_gr=DB_selectArray($db,"select group_name from servers_tsig_groups left join tkeys_groups on tkeys_groups.rowid=servers_tsig_groups.tsig_group_id where servers_tsig_groups.user_id=$USERID and servers_tsig_groups.server_id=$SrvId");
	if ($subres_gr) $groups=",{groups,[\"".implode('","',array_column($subres_gr,'group_name'))."\"]}"; else $groups="";

  $cfg.="{srv,{\"${row['ns']}\",\"".str_replace("@",".",$row['email'])."\",[\"".implode('","',array_column($subres,'name'))."\"$groups],[\"".implode('","',array_column($subres1,'mgmt_ip'))."\"]}}.\n";

  if ($row['certfile']!="" and $row['keyfile']!="") {
    $cfg.="\n% cert record: certfile, keyfile, cacertfile\n";
    $cfg.="{cert,{\"${row['certfile']}\",\"${row['keyfile']}\",\"${row['cacertfile']}\"}}.\n";
  };

  if ($row['custom_config']!="") {
    $cfg.="\n% Custom configuration\n";
    $cfg.="${row['custom_config']}\n\n";
	};
  
  //tkeys -- add groups TSIGs from servers and RPZ
  $cfg.="\n% tsig key record: name, alg, key\n";
  $row=DB_selectArray($db,"select rowid,* from tkeys where user_id=$USERID and (rowid in (select tsig_id from servers_tsig where server_id=$SrvId) or rowid in (select tsig_id from tkeys_tsig_groups left join servers_tsig_groups on servers_tsig_groups.tsig_group_id=tkeys_tsig_groups.tsig_group_id where server_id=$SrvId and servers_tsig_groups.user_id=$USERID) or rowid in (select tsig_id from tkeys_tsig_groups left join rpzs_tkeys_groups on rpzs_tkeys_groups.tkey_group_id=tkeys_tsig_groups.tsig_group_id left join rpzs_tkeys on rpzs_tkeys.rpz_id=rpzs_tkeys_groups.rpz_id left join rpzs_servers on rpzs_servers.rpz_id=rpzs_tkeys.rpz_id where server_id=$SrvId and rpzs_tkeys_groups.user_id=$USERID) or rowid in (select tkey_id from rpzs_tkeys left join rpzs on rpzs_tkeys.rpz_id=rpzs.rowid left join rpzs_servers on rpzs_servers.rpz_id=rpzs.rowid where server_id=$SrvId and rpzs.disabled=0));");
  foreach($row as $item){
		$subres_gr=DB_selectArray($db,"select group_name from tkeys_tsig_groups left join tkeys_groups on tkeys_groups.rowid=tkeys_tsig_groups.tsig_group_id where tkeys_tsig_groups.user_id=$USERID and tkeys_tsig_groups.tsig_id=${item['rowid']}");
		if ($subres_gr) $groups=",[\"".implode('","',array_column($subres_gr,'group_name'))."\"]"; else $groups="";
		$cfg.="{key,{\"${item['name']}\",\"${item['alg']}\",\"${item['tkey']}\"$groups}}.\n";
	};
  
  //whitelists
  $cfg.="\n% whitelist record: name, path, regex\n";
  $row=DB_selectArray($db,"select * from whitelists where user_id=$USERID and rowid in (select whitelist_id from rpzs_whitelists left join rpzs on rpzs_whitelists.rpz_id=rpzs.rowid left join rpzs_servers on rpzs_servers.rpz_id=rpzs.rowid where server_id=$SrvId);");
  foreach($row as $item){$cfg.="{whitelist,{\"${item['name']}\",\"${item['url']}\",".($item['regex']=="none"?"none":'"'.erlEscape($item['regex']).'"')."}}.\n";};

  //sources
  $cfg.="\n% source record: name, axfr_path, ixfr_path, regex\n";
  $row=DB_selectArray($db,"select * from sources where user_id=$USERID and rowid in (select source_id from rpzs_sources left join rpzs on rpzs_sources.rpz_id=rpzs.rowid left join rpzs_servers on rpzs_servers.rpz_id=rpzs.rowid where server_id=$SrvId);");
  foreach($row as $item){$cfg.="{source,{\"${item['name']}\",\"${item['url']}\",\"${item['url_ixfr']}\",".($item['regex']=="none"?"none":'"'.erlEscape($item['regex']).'"')."}}.\n";};
  
  //rpzs -- add groups {groups,["ip2"]},
  $cfg.="\n% rpz record: name, SOA refresh, SOA update retry, SOA expiration, SOA NXDomain TTL, Cache, Wildcards, Action, [tkeys], ioc_type, AXFR_time, IXFR_time, [sources], [notify], [whitelists]\n";
  $row=DB_selectArray($db,"select rpzs.rowid,* from rpzs left join rpzs_servers on rpzs_servers.rpz_id=rpzs.rowid where server_id=$SrvId and rpzs.user_id=$USERID and rpzs.disabled=0;");

  foreach($row as $item){
    $subres_tkeys=DB_selectArray($db,"select name from rpzs_tkeys left join tkeys on tkeys.rowid=rpzs_tkeys.tkey_id where rpzs_tkeys.user_id=$USERID and rpz_id=${item['rowid']}");  
		$subres_gr=DB_selectArray($db,"select group_name from rpzs_tkeys_groups left join tkeys_groups on tkeys_groups.rowid=rpzs_tkeys_groups.tkey_group_id where rpzs_tkeys_groups.user_id=$USERID and rpzs_tkeys_groups.rpz_id=${item['rowid']}");
	 if ($subres_gr) $groups=",{groups,[\"".implode('","',array_column($subres_gr,'group_name'))."\"]}"; else $groups="";

    $subres_srcs=DB_selectArray($db,"select name from rpzs_sources left join sources on sources.rowid=rpzs_sources.source_id where rpzs_sources.user_id=$USERID and rpz_id=${item['rowid']}");  
    $subres_wl=DB_selectArray($db,"select name from rpzs_whitelists left join whitelists on whitelists.rowid=rpzs_whitelists.whitelist_id where rpzs_whitelists.user_id=$USERID and rpz_id=${item['rowid']}");  
    $subres_notify=DB_selectArray($db,"select notify from rpzs_notify where user_id=$USERID and rpz_id=${item['rowid']}");  
        
    $cfg.="{rpz,{\"${item['name']}\",${item['soa_refresh']},${item['soa_update_retry']},${item['soa_expiration']},${item['soa_nx_ttl']},\"".($item['cache']?"true":"false")."\",\"".($item['wildcard']?"true":"false")."\",".erlAction($item['action']).",[\"".implode('","',array_column($subres_tkeys,'name'))."\"$groups],\"${item['ioc_type']}\",${item['axfr_update']},${item['ixfr_update']},[\"".implode('","',array_column($subres_srcs,'name'))."\"],[".(empty($subres_notify)?"":"\"".implode('","',array_column($subres_notify,'notify'))."\"")."],[".(empty($subres_wl)?"":"\"".implode('","',array_column($subres_wl,'name'))."\"")."]}}.\n";
  };
  
  $response['cfg']=$cfg;
  return $response;
};

function erlEscape($str){
  //TODO escape quotes
  return $str;
};

function erlChLRecords($str){
  //TODO check local RPZ records
  return $str;
};

function erlAction($str){
  switch($str){
    case "nxdomain":
    case "nodata":
    case "passthru":
    case "drop":
    case "tcp-only":
      $result='"'.$str.'"';
      break;
    default:
      $lstr="";$cmm="";
      foreach(explode(PHP_EOL,json_decode($str)) as $item){
        $lr=explode("=",$item,2);
        switch($lr[0]){
          case "local_aaaa":
            if(filter_var($lr[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          case "local_a":
            if(filter_var($lr[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          case "redirect_ip":
            if(filter_var($lr[1], FILTER_VALIDATE_IP)) $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          case "local_cname":
            if(filter_var($lr[1], FILTER_VALIDATE_DOMAIN)) $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          case "redirect_domain":
            if(filter_var($lr[1], FILTER_VALIDATE_DOMAIN)) $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          case "local_txt":
            $lstr.="$cmm{\"${lr[0]}\",\"${lr[1]}\"}";$cmm=",";
            break;
          default:
            break;
        };
      };
      $result=$lstr?"[$lstr]":'"nxdomain"';
      break;
  };
  return $result;
};

?>