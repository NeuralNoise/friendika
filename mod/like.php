<?php

require_once('include/security.php');
require_once('include/bbcode.php');
require_once('include/items.php');


function like_content(&$a) {

	if(! local_user() && ! remote_user()) {
		return;
	}

	$verb = notags(trim($_GET['verb']));

	if(! $verb)
		$verb = 'like';


	switch($verb) {
		case 'like':
			$activity = ACTIVITY_LIKE;
			break;
		case 'dislike':
			$activity = ACTIVITY_DISLIKE;
			break;
		case 'unlike':
			$activity = ACTIVITY_LIKE;
			break;
		case 'undislike':
			$activity = ACTIVITY_DISLIKE;
			break;
		default:
			return;
			break;
	}


	$item_id = (($a->argc > 1) ? notags(trim($a->argv[1])) : 0);

	logger('like: verb ' . $verb . ' item ' . $item_id);


	$r = q("SELECT * FROM `item` WHERE ( `id` = '%s' OR `uri` = '%s') AND `id` = `parent` LIMIT 1",
		dbesc($item_id),
		dbesc($item_id)
	);

	if(! $item_id || (! count($r))) {
		logger('like: no item ' . $item_id);
		return;
	}

	$item = $r[0];

	$owner_uid = $item['uid'];

	if(! can_write_wall($a,$owner_uid)) {
		return;
	}

	$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
		WHERE `contact`.`self` = 1 AND `contact`.`uid` = %d LIMIT 1",
		intval($owner_uid)
	);
	if(count($r))
		$owner = $r[0];

	if(! $owner) {
		logger('like: no owner');
		return;
	}


	if((local_user()) && (local_user() == $owner_uid)) {
		$contact = $owner;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($_SESSION['visitor_id']),
			intval($owner_uid)
		);
		if(count($r))
			$contact = $r[0];
	}
	if(! $contact) {
		return;
	}

	$r = q("SELECT `id` FROM `item` WHERE `verb` = '%s' AND `deleted` = 0 
		AND `contact-id` = %d AND ( `parent` = '%s' OR `parent-uri` = '%s') LIMIT 1",
		dbesc($activity),
		intval($contact['id']),
		dbesc($item_id),
		dbesc($item_id)
	);
	if(count($r)) {
		// Already voted, undo it
		$r = q("UPDATE `item` SET `deleted` = 1, `changed` = '%s' WHERE `id` = %d LIMIT 1",
			dbesc(datetime_convert()),
			intval($r[0]['id'])
		);

		$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');
		proc_close(proc_open("\"$php_path\" \"include/notifier.php\" \"like\" \"$post_id\" &",
			array(),$foo));
		return;
	}

	$uri = item_new_uri($a->get_hostname(),$owner_uid);

	$post_type = (($item['resource-id']) ? t('photo') : t('status'));
	$objtype = (($item['resource-id']) ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE ); 
	$link = xmlify('<link rel="alternate" type="text/html" href="' . $a->get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . '" />' . "\n") ;
	$body = $item['body'];

	$obj = <<< EOT

	<object>
		<type>$objtype</type>
		<local>1</local>
		<id>{$item['uri']}</id>
		<link>$link</link>
		<title></title>
		<content>$body</content>
	</object>
EOT;
	if($verb === 'like')
		$bodyverb = t('likes');
	if($verb === 'dislike')
		$bodyverb = t('doesn\'t like');

	if(! isset($bodyverb))
			return; 

	$arr = array();

	$arr['uri'] = $uri;
	$arr['uid'] = $owner_uid;
	$arr['contact-id'] = $contact['id'];
	$arr['type'] = 'activity';
	$arr['wall'] = 1;
	$arr['gravity'] = GRAVITY_LIKE;
	$arr['parent'] = $item['id'];
	$arr['parent-uri'] = $item['uri'];
	$arr['owner-name'] = $owner['name'];
	$arr['owner-link'] = $owner['url'];
	$arr['owner-avatar'] = $owner['thumb'];
	$arr['author-name'] = $contact['name'];
	$arr['author-link'] = $contact['url'];
	$arr['author-avatar'] = $contact['thumb'];
	$arr['body'] = '[url=' . $contact['url'] . ']' . $contact['name'] . '[/url]' . ' ' .  t('likes') . ' ' 
		. '[url=' . $owner['url'] . ']' . $owner['name'] . t('\'s') . '[/url]' . ' '
		. '[url=' . $a->get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . ']' . $post_type . '[/url]' ;

	$arr['verb'] = $activity;
	$arr['object-type'] = $objtype;
	$arr['object'] = $obj;
	$arr['allow_cid'] = $item['allow_cid'];
	$arr['allow_gid'] = $item['allow_gid'];
	$arr['deny_cid'] = $item['deny_cid'];
	$arr['deny_gid'] = $item['deny_gid'];
	$arr['visible'] = 1;
	$arr['unseen'] = 1;
	$arr['last-child'] = 0;

	$post_id = item_store($arr);	

	if(! $item['visible']) {
		$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['id']),
			intval($owner_uid)
		);
	}			


	$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');

	proc_close(proc_open("\"$php_path\" \"include/notifier.php\" \"like\" \"$post_id\" &",
		array(),$foo));

	return; // NOTREACHED
}