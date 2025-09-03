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
				$req =  new rXMLRPCRequest( array
				(
					new rXMLRPCCommand( "branch", array
					(
						$hash,
						getCmd("d.is_active="),
						getCmd('cat').'=$'.getCmd("d.stop").'=,$'.getCmd("d.throttle_name.set=").'slimit,$'.getCmd('d.start='),
						getCmd("d.throttle_name.set=").'slimit'
					)),
					new rXMLRPCCommand("view.set_visible",array($hash,"rlimit"))
				));
				$req->run();
				break;
			}
			case "finish":
			{
				trackersLimit::trace('Finished torrent from the public tracker '.$hash.' - stopping torrent');
				$req =  new rXMLRPCRequest( array
				(
					new rXMLRPCCommand( "d.close", array($hash) ),
					new rXMLRPCCommand( "d.stop", array($hash) )
				));
				$req->run();
				break;
			}
			case "resume":
{
    trackersLimit::trace('Resumed torrent from the public tracker '.$hash);
    // If the item is already complete, close it and force stopped state; else re-apply 'slimit' throttle
    $req = new rXMLRPCRequest(new rXMLRPCCommand("branch", array(
        $hash,
        getCmd("d.complete="),
        // then-branch: close + set stopped
        getCmd('cat').'=$'.getCmd('d.close').'=,$'.getCmd('d.state.set=').'0',
        // else-branch: ensure the throttle is applied
        getCmd('d.throttle_name.set=').'slimit'
    )));
    $req->run();
    break;
}
