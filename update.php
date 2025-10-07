<?php
if(count($argv)>3)
	$_SERVER['REMOTE_USER'] = $argv[4];
require_once(dirname(__FILE__).'/limits.php');
if(count($argv)>3)
{
	$tl = new trackersLimit();
	if($tl->loadLocal() && $tl->checkPublic($argv[1]))
	{
		$hash = $argv[2];
		$mode = $argv[3];
		switch($mode)
		{
			case "insert":
			{
				trackersLimit::trace('Added torrent from the public tracker '.$hash);
				// Check if torrent is active, then stop, set throttle, and restart
				$checkReq = new rXMLRPCRequest(new rXMLRPCCommand("d.is_active", array($hash)));
				if($checkReq->success() && $checkReq->val)
				{
					// Torrent is active - stop, set throttle, restart
					$req = new rXMLRPCRequest(array(
						new rXMLRPCCommand("d.try_stop", array($hash)),
						new rXMLRPCCommand("d.throttle_name.set", array($hash, "slimit")),
						new rXMLRPCCommand("d.try_start", array($hash)),
						new rXMLRPCCommand("d.views.push_back_unique", array($hash, "rlimit")),
						new rXMLRPCCommand("view.set_visible", array($hash, "rlimit"))
					));
				}
				else
				{
					// Torrent is not active - just set throttle
					$req = new rXMLRPCRequest(array(
						new rXMLRPCCommand("d.throttle_name.set", array($hash, "slimit")),
						new rXMLRPCCommand("d.views.push_back_unique", array($hash, "rlimit")),
						new rXMLRPCCommand("view.set_visible", array($hash, "rlimit"))
					));
				}
				$req->run();
				break;
			}
			case "finish":
			{
				trackersLimit::trace('Finished torrent from the public tracker '.$hash);
				$req = new rXMLRPCRequest(array(
					new rXMLRPCCommand("d.stop", array($hash)),
					new rXMLRPCCommand("d.close", array($hash))
				));
				$req->run();
				break;
			}
			case "resume":
			{
				trackersLimit::trace('Resumed torrent from the public tracker '.$hash);
				// Check if torrent is complete, then close it
				$checkReq = new rXMLRPCRequest(new rXMLRPCCommand("d.complete", array($hash)));
				if($checkReq->success() && $checkReq->val)
				{
					// Torrent is complete - close it
					$req = new rXMLRPCRequest(array(
						new rXMLRPCCommand("d.stop", array($hash)),
						new rXMLRPCCommand("d.close", array($hash))
					));
					$req->run();
				}
				break;
			}
		}
	}
}
