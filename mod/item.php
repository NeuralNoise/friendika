<?php

// This is the POST destination for most all locally posted
// text stuff. This function handles status, wall-to-wall status, 
// local comments, and remote coments - that are posted on this site 
// (as opposed to being delivered in a feed).
// All of these become an "item" which is our basic unit of 
// information. 

function item_post(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	$uid = local_user();

	$parent = ((x($_POST,'parent')) ? intval($_POST['parent']) : 0);

	$parent_item = null;

	if($parent) {
		$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
			intval($parent)
		);
		if(! count($r)) {
			notice( t('Unable to locate original post.') . EOL);
			goaway($a->get_baseurl() . "/" . $_POST['return'] );
		}
		$parent_item = $r[0];
	}

	$profile_uid = ((x($_POST,'profile_uid')) ? intval($_POST['profile_uid']) : 0);


	if(! can_write_wall($a,$profile_uid)) {
		notice( t('Permission denied.') . EOL) ;
		return;
	}

	$user = null;

	$r = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
		intval($profile_uid)
	);
	if(count($r))
		$user = $r[0];
	

	$str_group_allow   = perms2str($_POST['group_allow']);
	$str_contact_allow = perms2str($_POST['contact_allow']);
	$str_group_deny    = perms2str($_POST['group_deny']);
	$str_contact_deny  = perms2str($_POST['contact_deny']);

	$private = ((strlen($str_group_allow) || strlen($str_contact_allow) || strlen($str_group_deny) || strlen($str_contact_deny)) ? 1 : 0);

	if(($parent_item) && 
		(($parent_item['private']) 
			|| strlen($parent_item['allow_cid']) 
			|| strlen($parent_item['allow_gid']) 
			|| strlen($parent_item['deny_cid']) 
			|| strlen($parent_item['deny_gid'])
		)
	) {
		$private = 1;
	}

	$title             = notags(trim($_POST['title']));
	$body              = escape_tags(trim($_POST['body']));
	$location          = notags(trim($_POST['location']));
	$coord             = notags(trim($_POST['coord']));
	$verb              = notags(trim($_POST['verb']));

	if(! strlen($body)) {
		notice( t('Empty post discarded.') . EOL );
		goaway($a->get_baseurl() . "/" . $_POST['return'] );

	}

	// get contact info for poster

	$author = null;
	$self   = false;

	if(($_SESSION['uid']) && ($_SESSION['uid'] == $profile_uid)) {
		$self = true;
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($_SESSION['uid'])
		);
	}
	else {
		if((x($_SESSION,'visitor_id')) && (intval($_SESSION['visitor_id']))) {
			$r = q("SELECT * FROM `contact` WHERE `id` = %d LIMIT 1",
				intval($_SESSION['visitor_id'])
			);
		}
	}

	if(count($r)) {
		$author = $r[0];
		$contact_id = $author['id'];
	}

	// get contact info for owner
	
	if($profile_uid == $_SESSION['uid']) {
		$contact_record = $author;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($profile_uid)
		);
		if(count($r))
			$contact_record = $r[0];
	}

	$post_type = notags(trim($_POST['type']));

	if($post_type === 'net-comment') {
		if($parent_item !== null) {
			if($parent_item['type'] === 'remote') {
				$post_type = 'remote-comment';
			} 
			else {		
				$post_type = 'wall-comment';
			}
		}
	}


	/**
	 *
	 * When a photo was uploaded into the message using the (profile wall) ajax 
	 * uploader, The permissions are initially set to disallow anybody but the
	 * owner from seeing it. This is because the permissions may not yet have been
	 * set for the post. If it's private, the photo permissions should be set
	 * appropriately. But we didn't know the final permissions on the post until
	 * now. So now we'll look for links of uploaded messages that are in the
	 * post and set them to the same permissions as the post itself.
	 *
	 */

	$match = null;

	if(preg_match_all("/\[img\](.+?)\[\/img\]/",$body,$match)) {
		$images = $match[1];
		if(count($images)) {
			foreach($images as $image) {
				if(! stristr($image,$a->get_baseurl() . '/photo/'))
					continue;
				$image_uri = substr($image,strrpos($image,'/') + 1);
				$image_uri = substr($image_uri,0, strpos($image_uri,'-'));
				$r = q("UPDATE `photo` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'
					WHERE `resource-id` = '%s' AND `album` = '%s' ",
					dbesc($str_contact_allow),
					dbesc($str_group_allow),
					dbesc($str_contact_deny),
					dbesc($str_group_deny),
					dbesc($image_uri),
					dbesc( t('Wall Photos'))
				);
 
			}
		}
	}



	/**
	 * Look for any tags and linkify them
	 */

	$str_tags = '';
	$inform   = '';

	$tags = get_tags($body);


	if(count($tags)) {
		foreach($tags as $tag) {
			if(strpos($tag,'#') === 0) {
				$basetag = str_replace('_',' ',substr($tag,1));
				$body = str_replace($tag,'#[url=' . $a->get_baseurl() . '/search?search=' . rawurlencode($basetag) . ']' . $basetag . '[/url]',$body);
				if(strlen($str_tags))
					$str_tags .= ',';
				$str_tags .= '#[url=' . $a->get_baseurl() . '/search?search=' . rawurlencode($basetag) . ']' . $basetag . '[/url]';
				continue;
			}
			if(strpos($tag,'@') === 0) {
				$name = substr($tag,1);
				if((strpos($name,'@')) || (strpos($name,'http://'))) {
					$newname = $name;
					$links = @lrdd($name);
					if(count($links)) {
						foreach($links as $link) {
							if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
                    			$profile = $link['@attributes']['href'];
							if($link['@attributes']['rel'] === 'salmon') {
								if(strlen($inform))
									$inform .= ',';
                    			$inform .= 'url:' . str_replace(',','%2c',$link['@attributes']['href']);
							}
						}
					}
				}
				else {
					$newname = $name;
					if(strstr($name,'_')) {
						$newname = str_replace('_',' ',$name);
						$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($newname),
							intval($profile_uid)
						);
					}
					else {
						$r = q("SELECT * FROM `contact` WHERE `nick` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($name),
							intval($profile_uid)
						);
					}
					if(count($r)) {
						$profile = $r[0]['url'];
						$newname = $r[0]['name'];
						if(strlen($inform))
							$inform .= ',';
						$inform .= 'cid:' . $r[0]['id'];
					}
				}
				if($profile) {
					$body = str_replace($name,'[url=' . $profile . ']' . $newname	. '[/url]', $body);
					$profile = str_replace(',','%2c',$profile);
					if(strlen($str_tags))
						$str_tags .= ',';
					$str_tags .= '@[url=' . $profile . ']' . $newname	. '[/url]';
				}
			}
		}
	}

	$wall = 0;
	if($post_type === 'wall' || $post_type === 'wall-comment')
		$wall = 1;

	if(! strlen($verb))
		$verb = ACTIVITY_POST ;

	$gravity = (($parent) ? 6 : 0 );
 
	$notify_type = (($parent) ? 'comment-new' : 'wall-new' );

	$uri = item_new_uri($a->get_hostname(),$profile_uid);

	$datarray = array();
	$datarray['uid']           = $profile_uid;
	$datarray['type']          = $post_type;
	$datarray['wall']          = $wall;
	$datarray['gravity']       = $gravity;
	$datarray['contact-id']    = $contact_id;
	$datarray['owner-name']    = $contact_record['name'];
	$datarray['owner-link']    = $contact_record['url'];
	$datarray['owner-avatar']  = $contact_record['thumb'];
	$datarray['author-name']   = $author['name'];
	$datarray['author-link']   = $author['url'];
	$datarray['author-avatar'] = $author['thumb'];
	$datarray['created']       = datetime_convert();
	$datarray['edited']        = datetime_convert();
	$datarray['changed']       = datetime_convert();
	$datarray['uri']           = $uri;
	$datarray['title']         = $title;
	$datarray['body']          = $body;
	$datarray['location']      = $location;
	$datarray['coord']         = $coord;
	$datarray['tag']           = $str_tags;
	$datarray['inform']        = $inform;
	$datarray['verb']          = $verb;
	$datarray['allow_cid']     = $str_contact_allow;
	$datarray['allow_gid']     = $str_group_allow;
	$datarray['deny_cid']      = $str_contact_deny;
	$datarray['deny_gid']      = $str_group_deny;
	$datarray['private']       = $private;

	/**
	 * These fields are for the convenience of plugins...
	 * 'self' if true indicates the owner is posting on their own wall
	 * If parent is 0 it is a top-level post.
	 */

	$datarray['parent']        = $parent;
	$datarray['self']          = $self;


	call_hooks('post_local',$datarray);

	$r = q("INSERT INTO `item` (`uid`,`type`,`wall`,`gravity`,`contact-id`,`owner-name`,`owner-link`,`owner-avatar`, 
		`author-name`, `author-link`, `author-avatar`, `created`, `edited`, `changed`, `uri`, `title`, `body`, `location`, `coord`, 
		`tag`, `inform`, `verb`, `allow_cid`, `allow_gid`, `deny_cid`, `deny_gid`, `private` )
		VALUES( %d, '%s', %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d )",
		intval($datarray['uid']),
		dbesc($datarray['type']),
		intval($datarray['wall']),
		intval($datarray['gravity']),
		intval($datarray['contact-id']),
		dbesc($datarray['owner-name']),
		dbesc($datarray['owner-link']),
		dbesc($datarray['owner-avatar']),
		dbesc($datarray['author-name']),
		dbesc($datarray['author-link']),
		dbesc($datarray['author-avatar']),
		dbesc($datarray['created']),
		dbesc($datarray['edited']),
		dbesc($datarray['changed']),
		dbesc($datarray['uri']),
		dbesc($datarray['title']),
		dbesc($datarray['body']),
		dbesc($datarray['location']),
		dbesc($datarray['coord']),
		dbesc($datarray['tag']),
		dbesc($datarray['inform']),
		dbesc($datarray['verb']),
		dbesc($datarray['allow_cid']),
		dbesc($datarray['allow_gid']),
		dbesc($datarray['deny_cid']),
		dbesc($datarray['deny_gid']),
		intval($datarray['private'])
	);

	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' LIMIT 1",
		dbesc($datarray['uri']));
	if(count($r)) {
		$post_id = $r[0]['id'];
		logger('mod_item: saved item ' . $post_id);

		if($parent) {

			// This item is the last leaf and gets the comment box, clear any ancestors
			$r = q("UPDATE `item` SET `last-child` = 0, `changed` = '%s' WHERE `parent` = %d ",
				dbesc(datetime_convert()),
				intval($parent)
			);

			// Inherit ACL's from the parent item.

			$r = q("UPDATE `item` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s', `private` = %d
				WHERE `id` = %d LIMIT 1",
				dbesc($parent_item['allow_cid']),
				dbesc($parent_item['allow_gid']),
				dbesc($parent_item['deny_cid']),
				dbesc($parent_item['deny_gid']),
				intval($parent_item['private']),
				intval($post_id)
			);

			// Send a notification email to the conversation owner, unless the owner is me and I wrote this item
			if(($user['notify-flags'] & NOTIFY_COMMENT) && ($contact_record != $author)) {
				require_once('bbcode.php');
				$from = $author['name'];
				$tpl = load_view_file('view/cmnt_received_eml.tpl');			
				$email_tpl = replace_macros($tpl, array(
					'$sitename' => $a->config['sitename'],
					'$siteurl' =>  $a->get_baseurl(),
					'$username' => $user['username'],
					'$email' => $user['email'],
					'$from' => $from,
					'$display' => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'$body' => strip_tags(bbcode($datarray['body']))
				));

				$res = mail($user['email'], $from . t(" commented on your item at ") . $a->config['sitename'],
					$email_tpl,t("From: Administrator@") . $a->get_hostname() );
			}
		}
		else {
			$parent = $post_id;

			// let me know if somebody did a wall-to-wall post on my profile

			if(($user['notify-flags'] & NOTIFY_WALL) && ($contact_record != $author)) {
				require_once('bbcode.php');
				$from = $author['name'];
				$tpl = load_view_file('view/wall_received_eml.tpl');			
				$email_tpl = replace_macros($tpl, array(
					'$sitename' => $a->config['sitename'],
					'$siteurl' =>  $a->get_baseurl(),
					'$username' => $user['username'],
					'$email' => $user['email'],
					'$from' => $from,
					'$display' => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'$body' => strip_tags(bbcode($datarray['body']))
				));

				$res = mail($user['email'], $from . t(" posted on your profile wall at ") . $a->config['sitename'],
					$email_tpl,t("From: Administrator@") . $a->get_hostname() );
			}
		}

		$r = q("UPDATE `item` SET `parent` = %d, `parent-uri` = '%s', `changed` = '%s', `last-child` = 1, `visible` = 1
			WHERE `id` = %d LIMIT 1",
			intval($parent),
			dbesc(($parent == $post_id) ? $uri : $parent_item['uri']),
			dbesc(datetime_convert()),
			intval($post_id)
		);

		// photo comments turn the corresponding item visible to the profile wall
		// This way we don't see every picture in your new photo album posted to your wall at once.
		// They will show up as people comment on them.

		if(! $parent_item['visible']) {
			$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d LIMIT 1",
				intval($parent_item['id'])
			);
		}
	}

	$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');

	logger('mod_item: notifier invoked: ' . "\"$php_path\" \"include/notifier.php\" \"$notify_type\" \"$post_id\" &");

	proc_close(proc_open("\"$php_path\" \"include/notifier.php\" \"$notify_type\" \"$post_id\" &",
		array(),$foo));

	$datarray['id'] = $post_id;

	call_hooks('post_local_end', $datarray);
 

	goaway($a->get_baseurl() . "/" . $_POST['return'] );
	return; // NOTREACHED
}





function item_content(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	$uid = $_SESSION['uid'];

	if(($a->argc == 3) && ($a->argv[1] === 'drop') && intval($a->argv[2])) {

		// locate item to be deleted

		$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
			intval($a->argv[2])
		);

		if(! count($r)) {
			notice( t('Item not found.') . EOL);
			goaway($a->get_baseurl() . '/' . $_SESSION['return_url']);
		}
		$item = $r[0];

		// check if logged in user is either the author or owner of this item

		if(($_SESSION['visitor_id'] == $item['contact-id']) || ($_SESSION['uid'] == $item['uid'])) {

			// delete the item

			$r = q("UPDATE `item` SET `deleted` = 1, `body` = '', `edited` = '%s', `changed` = '%s' WHERE `id` = %d LIMIT 1",
				dbesc(datetime_convert()),
				dbesc(datetime_convert()),
				intval($item['id'])
			);

			// If item is a link to a photo resource, nuke all the associated photos 
			// (visitors will not have photo resources)
			// This only applies to photos uploaded from the photos page. Photos inserted into a post do not
			// generate a resource-id and therefore aren't intimately linked to the item. 

			if(strlen($item['resource-id'])) {
				$q("DELETE FROM `photo` WHERE `resource-id` = '%s' AND `uid` = %d ",
					dbesc($item['resource-id']),
					intval($item['uid'])
				);
				// ignore the result
			}

			// If it's the parent of a comment thread, kill all the kids

			if($item['uri'] == $item['parent-uri']) {
				$r = q("UPDATE `item` SET `deleted` = 1, `edited` = '%s', `changed` = '%s', `body` = '' 
					WHERE `parent-uri` = '%s' AND `uid` = %d ",
					dbesc(datetime_convert()),
					dbesc(datetime_convert()),
					dbesc($item['parent-uri']),
					intval($item['uid'])
				);
				// ignore the result
			}
			else {
				// ensure that last-child is set in case the comment that had it just got wiped.
				q("UPDATE `item` SET `last-child` = 0, `changed` = '%s' WHERE `parent-uri` = '%s' AND `uid` = %d ",
					dbesc(datetime_convert()),
					dbesc($item['parent-uri']),
					intval($item['uid'])
				);
				// who is the last child now? 
				$r = q("SELECT `id` FROM `item` WHERE `parent-uri` = '%s' AND `type` != 'activity' AND `deleted` = 0 AND `uid` = %d ORDER BY `edited` DESC LIMIT 1",
					dbesc($item['parent-uri']),
					intval($item['uid'])
				);
				if(count($r)) {
					q("UPDATE `item` SET `last-child` = 1 WHERE `id` = %d LIMIT 1",
						intval($r[0]['id'])
					);
				}	
			}
			$drop_id = intval($item['id']);
			$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');
			
			// send the notification upstream/downstream as the case may be

			proc_close(proc_open("\"$php_path\" \"include/notifier.php\" \"drop\" \"$drop_id\" &",
				array(), $foo));

			goaway($a->get_baseurl() . '/' . $_SESSION['return_url']);
			return; //NOTREACHED
		}
		else {
			notice( t('Permission denied.') . EOL);
			goaway($a->get_baseurl() . '/' . $_SESSION['return_url']);
			//NOTREACHED
		}
	}
}