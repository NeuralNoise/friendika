<?php

	if(!(x($a->page,'nav')))
		$a->page['nav'] = '';

	$a->page['nav'] .= '<div id="panel" style="display: none;"></div>' ;

	$a->page['nav'] .= '<div id="site-location">' . substr($a->get_baseurl(),strpos($a->get_baseurl(),'//') + 2 ) . '</div>';
	if(local_user()) {
		$a->page['nav'] .= '<a id="nav-logout-link" class="nav-link" href="logout">' . t('Logout') . "</a>\r\n";
	}
	else {
		$a->page['nav'] .= '<a id="nav-login-link" class="nav-login-link" href="login">' . t('Login') . "</a>\r\n";
	}

	$a->page['nav'] .= "<span id=\"nav-link-wrapper\" >\r\n";

	// This should take you home from a remote profile connection

	$homelink = ((x($_SESSION,'visitor_home')) ? $_SESSION['visitor_home'] : '');

	if(($a->module != 'home') && (! (local_user()))) 
		$a->page['nav'] .= '<a id="nav-home-link" class="nav-commlink" href="' . $homelink . '">' . t('Home') . "</a>\r\n";
	if(($a->config['register_policy'] == REGISTER_OPEN) && (! local_user()) && (! remote_user()))
		$a->page['nav'] .= '<a id="nav-register-link" class="nav-commlink" href="register" >' 
			. t('Register') . "</a>\r\n";

	$a->page['nav'] .= '<a id="nav-search-link" class="nav-link" href="search">' . t('Search') . "</a>\r\n";
	$a->page['nav'] .= '<a id="nav-directory-link" class="nav-link" href="directory">' . t('Directory') . "</a>\r\n";

	if(x($_SESSION,'uid')) {

		$a->page['nav'] .= '<a id="nav-network-link" class="nav-commlink" href="network">' . t('Network') 
			. '</a><span id="net-update" class="nav-ajax-left"></span>' . "\r\n";

		$a->page['nav'] .= '<a id="nav-home-link" class="nav-commlink" href="profile/' . $a->user['nickname'] . '">' 
			. t('Home') . '</a><span id="home-update" class="nav-ajax-left"></span>' . "\r\n";

		// only show friend requests for normal pages. Other page types have automatic friendship.

		if($_SESSION['page_flags'] == PAGE_NORMAL) {
			$a->page['nav'] .= '<a id="nav-notify-link" class="nav-commlink" href="notifications">' . t('Notifications') 
				. '</a><span id="notify-update" class="nav-ajax-left"></span>' . "\r\n";
		}

		$a->page['nav'] .= '<a id="nav-messages-link" class="nav-commlink" href="message">' . t('Messages') 
			. '</a><span id="mail-update" class="nav-ajax-left"></span>' . "\r\n";
		

		$a->page['nav'] .= '<a id="nav-settings-link" class="nav-link" href="settings">' . t('Settings') . "</a>\r\n";

		$a->page['nav'] .= '<a id="nav-profiles-link" class="nav-link" href="profiles">' . t('Profiles') . "</a>\r\n";

		$a->page['nav'] .= '<a id="nav-contacts-link" class="nav-link" href="contacts">' . t('Contacts') . "</a>\r\n";

		
	}

	$a->page['nav'] .= "</span>\r\n<span id=\"nav-end\"></span>\r\n";

	$banner = get_config('system','banner');

	if($banner === false) 
		$banner .= '<a href="http://friendika.com"><img id="logo-img" src="images/ff-32.jpg" alt="logo" /></a><span id="logo-text"><a href="http://friendika.com">Friendika</a></span>';


	$a->page['nav'] .= '<span id="banner">' . $banner . '</span>';

	call_hooks('page_header', $a->page['nav']);
