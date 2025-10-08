<?php

require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once( dirname(__FILE__)."/../../php/Snoopy.class.inc");
require_once( dirname(__FILE__)."/../../php/cache.php");
require_once( dirname(__FILE__)."/../../php/settings.php");
eval( FileUtil::getPluginConf('plimits') );

// Use KiB/s for modern *.set_kb methods (10 MiB/s = 10240 KiB/s)
@define('MAX_SPEED_KIB', 10 * 1024, true);

class speedLimit
{
	protected function collect()
	{
		$toCorrect = array();
		$req = new rXMLRPCRequest(
			new rXMLRPCCommand( "d.multicall2", array(
				"", "main",
				getCmd("d.hash="),
				getCmd("d.throttle_name="),
				getCmd('cat').'=$'.getCmd("throttle.up.rate").'=$'.getCmd("d.throttle_name="),
				getCmd('cat').'=$'.getCmd("throttle.down.rate").'=$'.getCmd("d.throttle_name=")))
			);
		if($req->success())
		{
			for($i=0; $i<count($req->val); $i+=4)
			{
				if($req->val[$i+1]=="slimit")
				{
					if(($req->val[$i+2]==="-1") && ($req->val[$i+3]==="-1"))
					{
						$toCorrect[] = $req->val[$i];
					}
				}
			}
		}
		return($toCorrect);
	}

	protected function correct( $toCorrect )
	{
		$req = new rXMLRPCRequest();
		foreach($toCorrect as $hash)
		{
			// Check if torrent is active
			$checkReq = new rXMLRPCRequest(new rXMLRPCCommand("d.is_active", array($hash)));
			if($checkReq->success() && $checkReq->val)
			{
				// Active: stop, set throttle, start
				$req->addCommand(new rXMLRPCCommand("d.try_stop", array($hash)));
				$req->addCommand(new rXMLRPCCommand("d.throttle_name.set", array($hash, "slimit")));
				$req->addCommand(new rXMLRPCCommand("d.try_start", array($hash)));
			}
			else
			{
				// Not active: just set throttle
				$req->addCommand(new rXMLRPCCommand("d.throttle_name.set", array($hash, "slimit")));
			}
		}
		if($req->getCommandsCount())
			return($req->success());
		return(true);
	}

	public function check( $req, $hash, $name, $public )
	{
		if( ($name=='slimit') && !$public )
		{
			trackersLimit::trace('Remove throttle restriction from '.$hash);
			$req->addCommand(new rXMLRPCCommand("d.try_stop", array($hash)));
			$req->addCommand(new rXMLRPCCommand("d.throttle_name.set", array($hash, "")));
			$req->addCommand(new rXMLRPCCommand("d.try_start", array($hash)));
		}
		else if( ($name!='slimit') && $public )
		{
			trackersLimit::trace('Add throttle restriction to '.$hash);
			$req->addCommand(new rXMLRPCCommand("d.try_stop", array($hash)));
			$req->addCommand(new rXMLRPCCommand("d.throttle_name.set", array($hash, "slimit")));
			$req->addCommand(new rXMLRPCCommand("d.try_start", array($hash)));
		}
	}

	public function init()
	{
		global $MAX_UL_LIMIT;
		global $MAX_DL_LIMIT;
		
		// Probe global limits with modern API (returns bytes/s)
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand( "throttle.global_up.max_rate" ),
			new rXMLRPCCommand( "throttle.global_down.max_rate" ) ));
		if($req->success())
		{
			// Set defaults via *.set_kb (expects KiB/s)
			$req1 = new rXMLRPCRequest();
			if($req->val[0]==0)
				$req1->addCommand(new rXMLRPCCommand( "throttle.global_up.max_rate.set_kb", MAX_SPEED_KIB ));
			if($req->val[1]==0)
				$req1->addCommand(new rXMLRPCCommand( "throttle.global_down.max_rate.set_kb", MAX_SPEED_KIB ));
			if(!$req1->getCommandsCount() || $req1->success())
			{
				$toCorrect = $this->collect();
				// Named throttle values use modern commands and KiB/s
				$req = new rXMLRPCRequest( array(
					new rXMLRPCCommand("throttle.up.max_rate.set_kb", array("slimit", $MAX_UL_LIMIT)),
					new rXMLRPCCommand("throttle.down.max_rate.set_kb", array("slimit", $MAX_DL_LIMIT))
					)
				);
				return($req->success() && $this->correct( $toCorrect ));
			}
		}
		return(false);
	}
}

class ratioLimit
{
	protected function correct()
	{
		$cmd = new rXMLRPCCommand("d.multicall2",array("", "main", getCmd("d.hash=")));
		$cmd->addParameters( array( getCmd("d.views.has")."=rlimit", getCmd("view.set_not_visible")."=rlimit" ) );
		$req = new rXMLRPCRequest($cmd);
		$req->setParseByTypes();
		if($req->success())
		{
			$req1 = new rXMLRPCRequest();
			foreach($req->strings as $no=>$hash)
			{
				if($req->i8s[$no*2]==1)
					$req1->addCommand(new rXMLRPCCommand("view.set_visible",array($hash,"rlimit")));
			}
			return(($req1->getCommandsCount()==0) || ($req1->success()));
		}
		return(false);
	}

	protected function flush()
	{
		global $RATIO_LIMIT;
		$req1 = new rXMLRPCRequest(new rXMLRPCCommand("view.list"));
		if($req1->success())
		{
			$req = new rXMLRPCRequest();
			if(!in_array("rlimit",$req1->val))
				$req->addCommand(new rXMLRPCCommand("view.add", array("rlimit")));
			
			$req->addCommand(new rXMLRPCCommand("view.filter_on", array("rlimit", "d.ratio=")));
			$req->addCommand(new rXMLRPCCommand("group2.rlimit.view.set", array("rlimit")));
			$req->addCommand(new rXMLRPCCommand("group2.rlimit.ratio.enable",array()));
			$req->addCommand(new rXMLRPCCommand("group2.rlimit.ratio.min.set", array($RATIO_LIMIT)));
			$req->addCommand(new rXMLRPCCommand("group2.rlimit.ratio.max.set", array($RATIO_LIMIT)));
			$req->addCommand(new rXMLRPCCommand("group2.rlimit.ratio.upload.set", array(0)));
			$req->addCommand(new rXMLRPCCommand("method.set", array("group2.rlimit.ratio.command", getCmd("d.stop=")."; ".getCmd("d.close="))));
			return($req->success());
		}
		return(false);
	}

	public function check( $req, $hash, $present, $public )
	{
		if( $present && !$public )
		{
			trackersLimit::trace('Remove ratio restriction from '.$hash);
			$req->addCommand(new rXMLRPCCommand("view.set_not_visible",array($hash, 'rlimit')));
			$req->addCommand(new rXMLRPCCommand("d.views.remove",array($hash, 'rlimit')));
		}
		else if( !$present && $public )
		{
			trackersLimit::trace('Add ratio restriction to '.$hash);
			$req->addCommand(new rXMLRPCCommand("d.views.push_back_unique",array($hash, 'rlimit')));
			$req->addCommand(new rXMLRPCCommand("view.set_visible",array($hash, 'rlimit')));
		}
	}

	public function init()
	{
		return($this->flush() && $this->correct());
	}
}

class trackersLimit
{
	protected $sl;
	protected $rl;
	protected $trackers = array();

	public function init()
	{
		global $trackersCheckInterval;
		global $preventUpload;

		$this->sl = new speedLimit();
		$this->rl = new ratioLimit();

		if( $this->sl->init() && $this->rl->init() )
		{
			$req = new rXMLRPCRequest(
				rTorrentSettings::get()->getOnInsertCommand( array('_plimits'.User::getUser(),
					getCmd('execute.nothrow').'={'.Utility::getPHP().','.dirname(__FILE__).'/update.php,"$'.
					getCmd('t.multicall').'=$'.getCmd('d.hash').'=,'.getCmd('t.url').'=,'.getCmd('cat').'=#",$'.getCmd('d.hash').'=,insert,'.User::getLogin().'}' ) ) );
			if($preventUpload)
			{
				$req->addCommand(
					rTorrentSettings::get()->getOnFinishedCommand(array('_plimits1'.User::getUser(),
					getCmd('execute.nothrow').'={'.Utility::getPHP().','.dirname(__FILE__).'/update.php,"$'.
					getCmd('t.multicall').'=$'.getCmd('d.hash').'=,'.getCmd('t.url').'=,'.getCmd('cat').'=#",$'.getCmd('d.hash').'=,finish,'.User::getLogin().'}' ) ) );
				$req->addCommand(
					rTorrentSettings::get()->getOnResumedCommand(array('_plimits2'.User::getUser(),
					getCmd('execute.nothrow').'={'.Utility::getPHP().','.dirname(__FILE__).'/update.php,"$'.
					getCmd('t.multicall').'=$'.getCmd('d.hash').'=,'.getCmd('t.url').'=,'.getCmd('cat').'=#",$'.getCmd('d.hash').'=,resume,'.User::getLogin().'}' ) ) );
			}
			if($req->success())
			{
				$this->check();
				$req = new rXMLRPCRequest( rTorrentSettings::get()->getScheduleCommand('plimits',$trackersCheckInterval,
					getCmd('execute').'={sh,-c,'.escapeshellarg(Utility::getPHP()).' '.escapeshellarg(dirname(__FILE__).'/check.php').' '.escapeshellarg(User::getUser()).' &}' ) );
				return( $req->success() );
			}
		}
		return(false);
	}

	public function loadLocal()
	{
		$this->trackers = array();
		$fname = FileUtil::getSettingsPath()."/trackers.lst";
		if(!is_readable($fname))
			$fname = dirname(__FILE__)."/trackers.lst";
		$results = file_get_contents($fname);
		if($results!==false)
		{
			$this->trackers = explode("\n", $results);
			return(true);
		}
		return(false);
	}

	public function load()
	{
		global $profileMask;
		$fname = FileUtil::getSettingsPath()."/trackers.lst";
		if(!is_readable($fname))
			$fname = dirname(__FILE__)."/trackers.lst";
		$ftime = filemtime($fname);
		$client = new Snoopy();
		$this->trackers = array();
		if($ftime!==false)
			$client->rawheaders['If-Modified-Since'] = gmdate('D, d M Y H:i:s T', $ftime);
		$this->loadLocal();
		return(false);
	}

public function checkPublic( $trackers )
{
    // Treat DHT-only or trackerless torrents as public
    if (empty(trim($trackers))) return true;

    global $enableOnDHT;
    if ($enableOnDHT && strpos($trackers, "dht://") !== false)
        return true;

    foreach ($this->trackers as $trk)
        if (!empty($trk) && stristr($trackers, $trk) !== false)
            return true;

    return false;
}

	public function check()
	{
		if($this->loadLocal())
		{
			$req =  new rXMLRPCRequest(
				new rXMLRPCCommand("d.multicall2",array("", "main",
					getCmd("d.hash="),
					getCmd("d.throttle_name="),
					getCmd("d.views.has")."=rlimit",
					getCmd("cat").'="$'.getCmd("t.multicall=").getCmd("d.hash=").",".getCmd("t.url")."=,".getCmd("cat=#").'"'
				)));
			if($req->success())
			{
				$req1 = new rXMLRPCRequest();
				for($i=0; $i<count($req->val); $i+=4)
				{
					$public = $this->checkPublic($req->val[$i+3]);
					self::trace($req->val[$i].' is '.($public ? 'public' : 'private').' throttle '.$req->val[$i+1].' hasratio '.$req->val[$i+2]);
					$this->sl->check( $req1, $req->val[$i], $req->val[$i+1], $public );
					$this->rl->check( $req1, $req->val[$i], ($req->val[$i+2]==1), $public );
				}
				return(!$req1->getCommandsCount() || $req1->success());
			}
		}
		return(false);
	}

	static public function trace( $msg, $err = false )
	{
		global $log_debug;
		if($log_debug)
		{
			FileUtil::toLog( $msg );
			if($err)
			{
				$dbg = error_get_last();
				if($dbg)
					FileUtil::toLog(print_r($dbg,true));
			}
		}
	}
}
