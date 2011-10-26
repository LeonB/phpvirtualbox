<?php
/*
 * $Id$
 */

class phpvbAuthWebAuth implements phpvbAuth {
	
	var $capabilities = array(
			'canChangePassword' => false,
			'canLogout' => false
		);
	
	var $config = array(
		'serverUserKey' => 'HTTP_X_WEBAUTH_USER',
		'adminUser' => null
	);
	
	function phpvbAuthWebAuth($userConfig = null) {
		if($userConfig) $this->config = array_merge($this->config,$userConfig);
	}
	
	function login($username, $password)
	{
	}
	
	function autoLoginHook()
	{
		global $_SESSION;
		// WebAuth passthrough
		if ( isset($_SERVER[$this->config['serverUserKey']]) )
		{
			$_SESSION['valid'] = true;
			$_SESSION['user'] = $_SERVER[$this->config['serverUserKey']];
			$_SESSION['admin'] = ($_SESSION['user'] === $this->config['adminUser']);
			$_SESSION['authCheckHeartbeat'] = time();			
		}
	}
	
	function heartbeat($vbox)
	{
		global $_SESSION;
		if ( isset($_SERVER[$this->config['serverUserKey']]) )
		{
			$_SESSION['valid'] = true;
			$_SESSION['authCheckHeartbeat'] = time();
		}
	}
	
	function changePassword($old, $new, &$response)
	{
	}
	
	function logout(&$response)
	{
		$response['data']['result'] = 1;
		if ( isset($this->config['logoutURL']) )
		{
			$response['data']['url'] = $this->config['logoutURL'];
		}
	}
	
	function listUsers()
	{
		
	}
	
	function updateUser($vboxRequest, $skipExistCheck)
	{
		
	}
	
	function deleteUser($user)
	{
		
	}
}
