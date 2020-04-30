<?php
$path = JPATH_ADMINISTRATOR . "/components/com_djclassifieds/lib/djcategory.php";
if (!file_exists($path)) {
	throw new RuntimeException("File $path not found!");
}

require_once $path;

class MirgarApiResourceAppeal extends ApiResource
{
	private const ICON_TEMPLATE = '/images/imgcat/0/%1$d_%1$d_ths.jpg';
	
	public function get()
	{
		ob_start();
		try {
			$dbo = JFactory::getDbo();
			
			$query = $dbo->getQuery(true);

			$query->select(array("*"))->from('#__djcf_items', 'i');
			
			$dbo->setQuery($query);
			
			$result = array_map("self::mapItem", $dbo->loadObjectList());

			foreach ($result as $item) {
				$query = "SELECT id, path, name, ext FROM #__djcf_images WHERE item_id=" . $item->id . " AND type='item' AND fromUser=0 ";
				$dbo->setQuery($query);
				$result->photos = array_map("self::mapImage", $dbo->loadObjectList());
			}
			
			$this->plugin->setResponse($result);
		}
		finally {
			ob_end_clean();
		}
	}
	
	public function post()
	{
		ob_start();
		try {
			// TODO: Consider add database transaction
			$app = JFactory::getApplication();
			JTable::addIncludePath(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_djclassifieds' . DS . 'tables');
			jimport('joomla.database.table');
			JPluginHelper::importPlugin('djclassifieds');
			require_once JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_djclassifieds' . DS . 'lib' . DS . 'djimage.php';
			$row = JTable::getInstance('Items', 'DJClassifiedsTable');
			$par = JComponentHelper::getParams('com_djclassifieds');
			$user = JFactory::getUser();
			$lang = JFactory::getLanguage();
			$dispatcher = JDispatcher::getInstance();

			$db = JFactory::getDBO();
			$id = JRequest::getVar('id', 0, '', 'int');
			$token 	= JRequest::getCMD('token', '');
			$redirect = '';

			$menus = $app->getMenu('site');

			$menu_newad_itemid = $menus->getItems('link', 'index.php?option=com_djclassifieds&view=additem', 1);
			$new_ad_link = 'index.php?option=com_djclassifieds&view=additem';
			if ($menu_newad_itemid) {
				$new_ad_link .= '&Itemid=' . $menu_newad_itemid->id;
			}
			$new_ad_link = JRoute::_($new_ad_link, false);


			if ($user->id == 0 && $id > 0) {
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
				//$redirect="index.php?option=com_djclassifieds&view=items&cid=0".$itemid;
				$redirect = DJClassifiedsSEO::getCategoryRoute('0:all');
				$redirect = JRoute::_($redirect, false);
				$app->redirect($redirect, $message, 'error');
			}

			$db = JFactory::getDBO();
			if ($id > 0) {
				$query = "SELECT user_id FROM #__djcf_items WHERE id='" . $id . "' LIMIT 1";
				$db->setQuery($query);
				$item_user_id = $db->loadResult();

				$wrong_ad = 0;

				if ($item_user_id != $user->id) {
					$wrong_ad = 1;
					if ($user->id && $par->get('admin_can_edit_delete', '0') && $user->authorise('core.admin')) {
						$wrong_ad = 0;
					}
				}


				if ($wrong_ad) {
					$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
					$redirect = DJClassifiedsSEO::getCategoryRoute('0:all');
					$redirect = JRoute::_($redirect, false);
					$app->redirect($redirect, $message, 'error');
				}
			}

			if ($par->get('user_type') == 1 && $user->id == '0') {
				//$uri = "index.php?option=com_djclassifieds&view=items&cid=0".$itemid;
				$uri = DJClassifiedsSEO::getCategoryRoute('0:all');
				$login_url = JRoute::_('index.php?option=com_users&view=login&return=' . base64_encode($uri), false);
				$app->redirect($login_url, JText::_('COM_DJCLASSIFIEDS_PLEASE_LOGIN'));
			}

			$input = \MongoDB\BSON\toPHP(file_get_contents("php://input"));
			$row->bind($input);

			if ($token && !$user->id && !$id) {
				$query = "SELECT i.id FROM #__djcf_items i "
					. "WHERE i.user_id=0 AND i.token=" . $db->Quote($db->escape($token));
				$db->setQuery($query);
				$ad_id = $db->loadResult();
				if ($ad_id) {
					$row->id = $ad_id;
				} else {
					$uri = DJClassifiedsSEO::getCategoryRoute('0:all');
					$login_url = JRoute::_('index.php?option=com_users&view=login&return=' . base64_encode($uri), false);
					$app->redirect($login_url, JText::_('COM_DJCLASSIFIEDS_PLEASE_LOGIN'));
				}
			}

			$dispatcher->trigger('onAfterInitialiseDJClassifiedsSaveAdvert', array(&$row, &$par));

			if ($par->get('title_char_limit', '0') > 0) {
				$row->name = mb_substr($row->name, 0, $par->get('title_char_limit', '100'), "UTF-8");
			}

			if ((int) $par->get('allow_htmltags', '0')) {
				$row->description = JRequest::getVar('description', '', 'post', 'string', JREQUEST_ALLOWRAW);

				$allowed_tags = explode(';', $par->get('allowed_htmltags', ''));
				$a_tags = '';
				for ($a = 0; $a < count($allowed_tags); $a++) {
					$a_tags .= '<' . $allowed_tags[$a] . '>';
				}

				$row->description = strip_tags($row->description, $a_tags);
			} else {
				$row->description = nl2br(JRequest::getVar('description', '', 'post', 'string'));
			}


			$row->intro_desc = mb_substr(strip_tags(nl2br($row->intro_desc)), 0, $par->get('introdesc_char_limit', '120'), "UTF-8");
			if (!$row->intro_desc) {
				$row->intro_desc = mb_substr(strip_tags($row->description), 0, $par->get('introdesc_char_limit', '120'), "UTF-8");
			}


			$row->contact = nl2br(JRequest::getVar('contact', '', 'post', 'string'));
			$row->price_negotiable = JRequest::getInt('price_negotiable', '0');
			$row->bid_min = str_ireplace(',', '.', JRequest::getVar('bid_min', '', 'post', 'string'));
			$row->bid_max = str_ireplace(',', '.', JRequest::getVar('bid_max', '', 'post', 'string'));
			$row->price_reserve = str_ireplace(',', '.', JRequest::getVar('price_reserve', '', 'post', 'string'));


			if (!$id && !$token && !$user->id && ($par->get('guest_can_edit', 0) || $par->get('guest_can_delete', 0))) {
				$characters = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
				$row->token = '';
				for ($p = 0; $p < 20; $p++) {
					$row->token .= $characters[mt_rand(0, strlen($characters))];
				}
			}

			$row->image_url = '';
			$duration_price = 0;
			if ($row->id == 0) {
				if ($par->get('durations_list', '')) {
					$exp_days = JRequest::getVar('exp_days', $par->get('exp_days'), '', 'int');
					$query = "SELECT * FROM #__djcf_days WHERE days = " . $exp_days;
					$db->setQuery($query);
					$duration = $db->loadObject();
					if ($duration) {
						$duration_price = $duration->price;
					} else {
						//$exp_days = $par->get('exp_days','7');						
						$message = JText::_('COM_DJCLASSIFIEDS_WRONG_DURATION_LIMIT');
						$app->redirect($new_ad_link, $message, 'error');
					}
				} else {
					$exp_days = $par->get('exp_days', '7');
				}

				if ($exp_days == 0) {
					$row->date_exp = "2038-01-01 00:00:00";
				} else {
					$row->date_exp = date("Y-m-d G:i:s", mktime(date("G"), date("i"), date("s"), date("m"), date("d") + $exp_days, date("Y")));
				}
				if ($row->date_exp == '1970-01-01 1:00:00') {
					$row->date_exp = '2038-01-19 00:00:00';
				}
				$row->exp_days = $exp_days;
				$row->date_start = date("Y-m-d H:i:s");
			}

			$row->date_mod = date("Y-m-d H:i:s");

			// $row->cat_id = end($_POST['cats']);
			// if (!$row->cat_id) {
			// 	$row->cat_id = $_POST['cats'][count($_POST['cats']) - 2];
			// }
			// $row->cat_id = str_ireplace('p', '', $row->cat_id);

			// $row->region_id = end($_POST['regions']);
			// if (!$row->region_id) {
			// 	$row->region_id = $_POST['regions'][count($_POST['regions']) - 2];
			// }

			// if (($row->region_id || $row->address) && (($row->latitude == '0.000000000000000' && $row->longitude == '0.000000000000000') || (!$row->latitude && !$row->longitude))) {
			// 	$address = '';
			// 	if ($row->region_id) {
			// 		$reg_path = DJClassifiedsRegion::getParentPath($row->region_id);
			// 		for ($r = count($reg_path) - 1; $r >= 0; $r--) {
			// 			if ($reg_path[$r]->country) {
			// 				$address = $reg_path[$r]->name;
			// 			}
			// 			if ($reg_path[$r]->city) {
			// 				if ($address) {
			// 					$address .= ', ';
			// 				}
			// 				$address .= $reg_path[$r]->name;
			// 			}
			// 		}
			// 	}
			// 	if ($address) {
			// 		$address .= ', ';
			// 	}
			// 	$address .= $row->address;
			// 	if ($row->post_code) {
			// 		$address .= ', ' . $row->post_code;
			// 	}

			// 	$loc_coord = DJClassifiedsGeocode::getLocation($address);
			// 	if (is_array($loc_coord)) {
			// 		$row->latitude = $loc_coord['lat'];
			// 		$row->longitude = $loc_coord['lng'];
			// 	}
			// }

			//echo '<pre>';print_r($row);die();
			if ($row->id == 0) {
				$row->user_id = $user->id;
				$row->ip_address = $_SERVER['REMOTE_ADDR'];
			}


			$row->promotions = '';
			if ($par->get('promotion', '1') == '1') {
				$query = "SELECT p.* FROM #__djcf_promotions p WHERE p.published=1 ORDER BY p.id ";
				$db->setQuery($query);
				$promotions = $db->loadObjectList('id');

				$query = "SELECT p.* FROM #__djcf_promotions_prices p ORDER BY p.days ";
				$db->setQuery($query);
				$prom_prices = $db->loadObjectList();
				foreach ($promotions as $prom) {
					$prom->prices = array();
				}
				foreach ($prom_prices as $prom_p) {
					if (isset($promotions[$prom_p->prom_id])) {
						$promotions[$prom_p->prom_id]->prices[$prom_p->days] = $prom_p;
					}
				}
			}

			$cat = '';
			if ($row->cat_id) {
				$query = "SELECT name,alias,price,autopublish FROM #__djcf_categories WHERE id = " . $row->cat_id;
				$db->setQuery($query);
				$cat = $db->loadObject();
				if (!$cat->alias) {
					$cat->alias = DJClassifiedsSEO::getAliasName($cat->name);
				}
			}

			$type = '';
			$type_price = 0;
			if ($row->type_id) {
				$type = DJClassifiedsPayment::getTypePrice($user->id, $row->type_id);
				$type_price = $type->price;
			}

			$is_new = 1;
			$old_promotions = '';
			if ($row->id > 0) {
				$query = "SELECT * FROM #__djcf_items WHERE id = " . $row->id;
				$db->setQuery($query);
				$old_row = $db->loadObject();

				$query = "SELECT * FROM #__djcf_fields WHERE edition_blocked = 1 ";
				$db->setQuery($query);
				$fields_blocked = $db->loadObjectList();
				$fields_blocked_where = '';
				if (count($fields_blocked)) {
					$fields_blocked_ids = '';
					foreach ($fields_blocked as $fb) {
						if ($fields_blocked_ids) {
							$fields_blocked_ids .= ',';
						}
						$fields_blocked_ids .= $fb->id;
					}
					$fields_blocked_where = 'AND field_id NOT IN (' . $fields_blocked_ids . ')';
				}

				$query = "DELETE FROM #__djcf_fields_values WHERE item_id= " . $row->id . " " . $fields_blocked_where;
				$db->setQuery($query);
				$db->query();

				$query = "DELETE FROM #__djcf_fields_values_sale WHERE item_id= " . $row->id . " ";
				$db->setQuery($query);
				$db->query();

				$query = "SELECT * FROM #__djcf_items_promotions WHERE item_id = " . $row->id . " ORDER BY id";
				$db->setQuery($query);
				$old_promotions = $db->loadObjectList('prom_id');

				$row->payed = $old_row->payed;
				$row->pay_type = $old_row->pay_type;
				$row->exp_days = $old_row->exp_days;
				$row->alias = $old_row->alias;
				$row->published = $old_row->published;
				$row->metarobots = $old_row->metarobots;
				$is_new = 0;
			}
			if (!$row->alias) {
				$row->alias = DJClassifiedsSEO::getAliasName($row->name);
			}

			$dispatcher->trigger('onBeforePaymentsDJClassifiedsSaveAdvert', array(&$row, $is_new, &$cat, &$promotions, &$type_price));


			if ($cat->autopublish == '0') {
				if ($par->get('autopublish') == '1') {
					$row->published = 1;
					if ($row->id) {
						$message = JText::_('COM_DJCLASSIFIEDS_AD_SAVED_SUCCESSFULLY');
					} else {
						$message = JText::_('COM_DJCLASSIFIEDS_AD_ADDED_SUCCESSFULLY');
					}
				} else {
					$row->published = 0;
					if ($row->id) {
						$message = JText::_('COM_DJCLASSIFIEDS_AD_SAVED_SUCCESSFULLY_WAITING_FOR_PUBLISH');
					} else {
						$message = JText::_('COM_DJCLASSIFIEDS_AD_ADDED_SUCCESSFULLY_WAITING_FOR_PUBLISH');
					}
					//$redirect="index.php?option=com_djclassifieds&view=items&cid=0".$itemid;
					$redirect = DJClassifiedsSEO::getItemRoute($row->id . ':' . $row->alias, $row->cat_id . ':' . $i->c_alias);
				}
			} elseif ($cat->autopublish == '1') {
				$row->published = 1;
				if ($row->id) {
					$message = JText::_('COM_DJCLASSIFIEDS_AD_SAVED_SUCCESSFULLY');
				} else {
					$message = JText::_('COM_DJCLASSIFIEDS_AD_ADDED_SUCCESSFULLY');
				}
			} elseif ($cat->autopublish == '2') {
				$row->published = 0;
				if ($row->id) {
					$message = JText::_('COM_DJCLASSIFIEDS_AD_SAVED_SUCCESSFULLY_WAITING_FOR_PUBLISH');
				} else {
					$message = JText::_('COM_DJCLASSIFIEDS_AD_ADDED_SUCCESSFULLY_WAITING_FOR_PUBLISH');
				}
				$redirect = DJClassifiedsSEO::getCategoryRoute('0:all');
			}

			$pay_redirect = 0;
			$row->pay_type = '';
			$row->payed = 1;
			if (isset($old_row)) {
				if ($cat->price == 0 && $row->promotions == '' && !strstr($old_row->pay_type, 'duration') && $type_price == 0) {
					$row->payed = 1;
					$row->pay_type = '';
				} else if (($old_row->cat_id != $row->cat_id && $cat->price > 0) || ($old_row->promotions != $row->promotions) || strstr($old_row->pay_type, 'duration') || $old_row->pay_type || ($old_row->type_id != $row->type_id && $type_price > 0)) {
					$row->pay_type = '';
					if ($old_row->cat_id != $row->cat_id && $cat->price > 0) {
						$row->pay_type = 'cat,';
					} else if ($old_row->cat_id == $row->cat_id && $cat->price > 0 && strstr($old_row->pay_type, 'cat')) {
						$row->pay_type = 'cat,';
					}
					if (strstr($old_row->pay_type, 'duration')) {
						$row->pay_type .= 'duration,';
					}

					if (strstr($old_row->pay_type, 'type,') || ($type_price > 0 && $old_row->type_id != $row->type_id)) {
						$row->pay_type .= 'type,';
					}

					if ($row->pay_type) {
						$row->published = 0;
						$row->payed = 0;
						$pay_redirect = 1;
					}
				} else if ($row->payed == 0 && ($cat->price > 0 || $row->promotions != '')) {
					$row->payed = 0;
					$row->published = 0;
					$pay_redirect = 1;
				}
			} else if ($cat->price > 0 || $duration_price > 0 || $type_price > 0) {
				if ($cat->price > 0) {
					$row->pay_type .= 'cat,';
				}
				if ($duration_price > 0) {
					$row->pay_type .= 'duration,';
				}
				if ($type_price > 0) {
					$row->pay_type .= 'type,';
				}
				$row->published = 0;
				$row->payed = 0;
				$pay_redirect = 1;
			} else {
				$row->payed = 1;
				$row->pay_type = '';
			}

			$mcat_limit = JRequest::getInt('mcat_limit', 0);
			$mcat_ids = array();
			for ($mi = 0; $mi < $mcat_limit; $mi++) {
				$mcat = $app->input->get('mcats' . $mi, array(), 'ARRAY');
				if (count($mcat)) {
					$mc = intval(str_ireplace('p', '', end($mcat)));
					if ($mc > 0) {
						$mcat_ids[] = $mc;
					}
				}
			}

			if (count($mcat_ids)) {
				$mcat_ids = implode(',', $mcat_ids);
				if ($is_new) {
					$query = "SELECT * FROM #__djcf_categories WHERE id IN (" . $mcat_ids . ") AND price>0 ";
					$db->setQuery($query);
					$mcat_list = $db->loadObjectList();

					foreach ($mcat_list as $mc) {
						$row->pay_type .= 'mc' . $mc->id . ',';
						$row->published = 0;
						$row->payed = 0;
						$pay_redirect = 1;
					}
				} else {

					$query = "SELECT * FROM #__djcf_items_categories WHERE item_id= " . $row->id . " ";
					$db->setQuery($query);
					$mcat_old_list = $db->loadObjectList('cat_id');

					$query = "SELECT * FROM #__djcf_categories WHERE id IN (" . $mcat_ids . ") AND price>0 ";
					$db->setQuery($query);
					$mcat_list = $db->loadObjectList();
					foreach ($mcat_list as $mc) {
						$add_mc = 0;
						if (!isset($mcat_old_list[$mc->id])) {
							$add_mc = 1;
						} else if (strstr($old_row->pay_type, 'mc' . $mc->id . ',')) {
							$add_mc = 1;
						}

						if ($add_mc) {
							$row->pay_type .= 'mc' . $mc->id . ',';
							$row->published = 0;
							$row->payed = 0;
							$pay_redirect = 1;
						}
					}
				}
			}

			if ($user->id && $par->get('ad_preview', '0') && JRequest::getInt('preview_value', 0)) {
				$row->published = 0;
			}

			$dispatcher->trigger('onBeforeDJClassifiedsSaveAdvert', array(&$row, $is_new));

			if ($row->pay_type) {
				$pay_redirect = 1;
			}

			if (!$row->store()) {
			}
			if ($is_new) {
				$query = "UPDATE #__djcf_items SET date_sort=date_start WHERE id=" . $row->id . " ";
				$db->setQuery($query);
				$db->query();
			}

			//#region Images
			$item_images = '';
			$images_c = 0;
			if (!$is_new) {
				$query = "SELECT * FROM #__djcf_images WHERE item_id=" . $row->id . " AND type='item' AND fromUser=0 ";
				$db->setQuery($query);
				$item_images = $db->loadObjectList('id');
				$images_c = count($item_images);
			}

			$img_ids = JRequest::getVar('img_id', array(), 'post', 'array');
			$img_captions = JRequest::getVar('img_caption', array(), 'post', 'array');
			$img_images = JRequest::getVar('img_image', array(), 'post', 'array');
			$img_rotate = JRequest::getVar('img_rotate', array(), 'post', 'array');

			$img_id_to_del = '';

			if ($item_images) {
				foreach ($item_images as $item_img) {
					$img_to_del = 1;
					foreach ($img_ids as $img_id) {
						if ($item_img->id == $img_id) {
							$img_to_del = 0;
							break;
						}
					}
					if ($img_to_del) {
						$images_c--;
						$path_to_delete = JPATH_ROOT . $item_img->path . $item_img->name;
						if (JFile::exists($path_to_delete . '.' . $item_img->ext)) {
							JFile::delete($path_to_delete . '.' . $item_img->ext);
						}
						if (JFile::exists($path_to_delete . '_ths.' . $item_img->ext)) {
							JFile::delete($path_to_delete . '_ths.' . $item_img->ext);
						}
						if (JFile::exists($path_to_delete . '_thm.' . $item_img->ext)) {
							JFile::delete($path_to_delete . '_thm.' . $item_img->ext);
						}
						if (JFile::exists($path_to_delete . '_thb.' . $item_img->ext)) {
							JFile::delete($path_to_delete . '_thb.' . $item_img->ext);
						}
						$img_id_to_del .= $item_img->id . ',';
					}
				}
				if ($img_id_to_del) {
					$query = "DELETE FROM #__djcf_images WHERE item_id=" . $row->id . " AND type='item' AND ID IN (" . substr($img_id_to_del, 0, -1) . ") ";
					$db->setQuery($query);
					$db->query();
				}
			}

			$last_id = $row->id;

			$imglimit = $par->get('img_limit', '3');
			$nw = (int) $par->get('th_width', -1);
			$nh = (int) $par->get('th_height', -1);
			$nws = (int) $par->get('smallth_width', -1);
			$nhs = (int) $par->get('smallth_height', -1);
			$nwm = (int) $par->get('middleth_width', -1);
			$nhm = (int) $par->get('middleth_height', -1);
			$nwb = (int) $par->get('bigth_width', -1);
			$nhb = (int) $par->get('bigth_height', -1);

			$img_ord = 1;
			$img_to_insert = 0;
			$query_img = "INSERT INTO #__djcf_images(`item_id`,`type`,`name`,`ext`,`path`,`caption`,`ordering`) VALUES ";
			$new_img_path_rel = DJClassifiedsImage::generatePath($par->get('advert_img_path', '/components/com_djclassifieds/images/item/'), $last_id);
			$new_img_path = JPATH_SITE . $new_img_path_rel;

			foreach ($input->photos as $photo) { // TODO: Move it into function saveNewPhotos
				if ($images_c >= $imglimit) {
					break;
				}
				$bytes = $photo->content->getData();
				if (!empty($bytes)) {
					$new_img_n = $last_id . '_' . str_ireplace(' ', '_', $photo->name . '.' . $photo->ext);
					$new_img_n = $lang->transliterate($new_img_n);
					$new_img_n = strtolower($new_img_n);
					$new_img_n = JFile::makeSafe($new_img_n);

					$nimg = 0;
					$name_parts = pathinfo($new_img_n);
					$img_name = $name_parts['filename'];
					$img_ext = $name_parts['extension'];
					$new_path_check = $new_img_path . $new_img_n;
					$new_path_check = str_ireplace('.' . $img_ext, '_thm.' . $img_ext, $new_path_check);

					while (JFile::exists($new_path_check)) {
						$nimg++;
						$new_img_n = $last_id . '_' . $nimg . '_' . str_ireplace(' ', '_', $photo->name . '.' . $photo->ext);
						$new_img_n = $lang->transliterate($new_img_n);
						$new_img_n = strtolower($new_img_n);
						$new_img_n = JFile::makeSafe($new_img_n);
						$new_path_check = $new_img_path . $new_img_n;

						$new_path_check = str_ireplace('.' . $img_ext, '_thm.' . $img_ext, $new_path_check);
					}

					$f = fopen($new_img_path . $new_img_n, "w");
					fwrite($f, $bytes);
					fclose($f);
					$name_parts = pathinfo($new_img_n);
					$img_name = $name_parts['filename'];
					$img_ext = $name_parts['extension'];

					$new_img_name_with_path = $new_img_path . $new_img_n;
					$image_name_with_path = $new_img_path . $img_name;

					DJClassifiedsImage::makeThumb(
						$new_img_name_with_path,
						$image_name_with_path . '_ths.' . $img_ext,
						$nws,
						$nhs
					);
					DJClassifiedsImage::makeThumb(
						$new_img_name_with_path,
						$image_name_with_path . '_thm.' . $img_ext,
						$nwm,
						$nhm
					);
					DJClassifiedsImage::makeThumb($new_img_path . $new_img_n, $new_img_path . $img_name . '_thb.' . $img_ext, $nwb, $nhb);
					$query_img .= "('" . $row->id . "','item','" . $img_name . "','" . $img_ext . "','" . $new_img_path_rel . "','" . $db->escape($photo->name) . "','" . $img_ord . "'), ";
					$img_to_insert++;
					if ($par->get('store_org_img', '1') == 0) {
						JFile::delete($new_img_path . $new_img_n);
					}
				}
				$images_c++;
			}

			// for ($im = 0; $im < count($img_ids); $im++) {
			// 	if ($img_ids[$im]) {
			// 		if ($img_rotate[$im] % 4 > 0) {
			// 			$img_rot = $item_images[$img_ids[$im]];
			// 			//echo $img_rotate[$im]%4;
			// 			//  			print_r($img_rot);die();


			// 			if ($par->get('leave_small_th', '0') == 0) {
			// 				if (JFile::exists($new_img_path . $img_rot->name . '_ths.' . $img_rot->ext)) {
			// 					JFile::delete($new_img_path . $img_rot->name . '_ths.' . $img_rot->ext);
			// 				}
			// 			}
			// 			if (JFile::exists($new_img_path . $img_rot->name . '_thm.' . $img_rot->ext)) {
			// 				JFile::delete($new_img_path . $img_rot->name . '_thm.' . $img_rot->ext);
			// 			}
			// 			if (JFile::exists($new_img_path . $img_rot->name . '_thb.' . $img_rot->ext)) {
			// 				JFile::delete($new_img_path . $img_rot->name . '_thb.' . $img_rot->ext);
			// 			}

			// 			DJClassifiedsImage::makeThumb($new_img_path . $img_rot->name . '.' . $img_rot->ext, $new_img_path . $img_rot->name . '_ths.' . $img_rot->ext, $nws, $nhs);
			// 			DJClassifiedsImage::makeThumb($new_img_path . $img_rot->name . '.' . $img_rot->ext, $new_img_path . $img_rot->name . '_thm.' . $img_rot->ext, $nwm, $nhm);
			// 			DJClassifiedsImage::makeThumb($new_img_path . $img_rot->name . '.' . $img_rot->ext, $new_img_path . $img_rot->name . '_thb.' . $img_rot->ext, $nwb, $nhb);

			// 			//print_r($img_ids);print_r($img_rotate[$im]);die();
			// 		}

			// 		if ($item_images[$img_ids[$im]]->ordering != $img_ord || $item_images[$img_ids[$im]]->caption != $img_captions[$im]) {
			// 			$query = "UPDATE #__djcf_images SET ordering='" . $img_ord . "', caption='" . $db->escape($img_captions[$im]) . "' WHERE item_id=" . $row->id . " AND type='item' AND id=" . $img_ids[$im] . " ";
			// 			$db->setQuery($query);
			// 			$db->query();
			// 		}
			// 	} else {
			// 		if ($images_c >= $imglimit) {
			// 			break;
			// 		}
			// 		$new_img_name = explode(';', $img_images[$im]);
			// 		if (is_array($new_img_name)) {
			// 			$new_img_name_u = JPATH_ROOT . '/tmp/djupload/' . $new_img_name[0];
			// 			if (JFile::exists($new_img_name_u)) {
			// 				if (getimagesize($new_img_name_u)) {
			// 					$new_img_n = $last_id . '_' . str_ireplace(' ', '_', $new_img_name[1]);
			// 					$new_img_n = $lang->transliterate($new_img_n);
			// 					$new_img_n = strtolower($new_img_n);
			// 					$new_img_n = JFile::makeSafe($new_img_n);

			// 					$nimg = 0;
			// 					$name_parts = pathinfo($new_img_n);
			// 					$img_name = $name_parts['filename'];
			// 					$img_ext = $name_parts['extension'];
			// 					$new_path_check = $new_img_path . $new_img_n;
			// 					$new_path_check = str_ireplace('.' . $img_ext, '_thm.' . $img_ext, $new_path_check);

			// 					while (JFile::exists($new_path_check)) {
			// 						$nimg++;
			// 						$new_img_n = $last_id . '_' . $nimg . '_' . str_ireplace(' ', '_', $new_img_name[1]);
			// 						$new_img_n = $lang->transliterate($new_img_n);
			// 						$new_img_n = strtolower($new_img_n);
			// 						$new_img_n = JFile::makeSafe($new_img_n);
			// 						$new_path_check = $new_img_path . $new_img_n;

			// 						$new_path_check = str_ireplace('.' . $img_ext, '_thm.' . $img_ext, $new_path_check);
			// 					}

			// 					rename($new_img_name_u, $new_img_path . $new_img_n);
			// 					$name_parts = pathinfo($new_img_n);
			// 					$img_name = $name_parts['filename'];
			// 					$img_ext = $name_parts['extension'];

			// 					DJClassifiedsImage::makeThumb($new_img_path . $new_img_n, $new_img_path . $img_name . '_ths.' . $img_ext, $nws, $nhs);
			// 					DJClassifiedsImage::makeThumb($new_img_path . $new_img_n, $new_img_path . $img_name . '_thm.' . $img_ext, $nwm, $nhm);
			// 					DJClassifiedsImage::makeThumb($new_img_path . $new_img_n, $new_img_path . $img_name . '_thb.' . $img_ext, $nwb, $nhb);
			// 					$query_img .= "('" . $row->id . "','item','" . $img_name . "','" . $img_ext . "','" . $new_img_path_rel . "','" . $db->escape($img_captions[$im]) . "','" . $img_ord . "'), ";
			// 					$img_to_insert++;
			// 					if ($par->get('store_org_img', '1') == 0) {
			// 						JFile::delete($new_img_path . $new_img_n);
			// 					}
			// 				}
			// 			}
			// 		}
			// 		$images_c++;
			// 	}
			// 	$img_ord++;
			// }

			if ($img_to_insert) {
				$query_img = substr($query_img, 0, -2) . ';';
				$db->setQuery($query_img);
				$db->query();
			}

			//#endregion

			$imgfreelimit = $par->get('img_free_limit', '-1');
			if ($imgfreelimit > -1 && $images_c > $imgfreelimit) {
				$extra_images = $images_c - $imgfreelimit;
				$images_to_pay = $extra_images;
				if (!$is_new) {
					if ($old_row->extra_images >= $images_to_pay) {
						$images_to_pay = 0;
					} else {
						$images_to_pay = $images_to_pay - $old_row->extra_images;
					}
				}

				$images_to_pay = $images_to_pay + $old_row->extra_images_to_pay;

				if ($images_to_pay > 0) {
					$row->extra_images = $extra_images;
					$row->extra_images_to_pay = $images_to_pay;
					$row->pay_type .= 'extra_img,';
					$row->published = 0;
					$row->payed = 0;
					$pay_redirect = 1;
					$row->store();
				}
			}


			$desc_chars_limit = $par->get('pay_desc_chars_free_limit', 0);
			$desc_c = strlen($row->description);
			if ($par->get('pay_desc_chars', 0) && $desc_c > $desc_chars_limit) {
				$extra_chars = $desc_c - $desc_chars_limit;
				$chars_to_pay = $extra_chars;
				if (!$is_new) {
					if ($old_row->extra_chars >= $chars_to_pay) {
						$chars_to_pay = 0;
					} else {
						$chars_to_pay = $chars_to_pay - $old_row->extra_chars;
					}
				}
				$chars_to_pay = $chars_to_pay + $old_row->extra_chars_to_pay;

				if ($chars_to_pay > 0) {
					$row->extra_chars = $extra_chars;
					$row->extra_chars_to_pay = $chars_to_pay;
					$row->pay_type .= 'extra_chars,';
					$row->published = 0;
					$row->payed = 0;
					$pay_redirect = 1;
					$row->store();
				}
			}


			$mcat_limit = JRequest::getInt('mcat_limit', 0);
			$mcats_list = '';
			if ($mcat_limit > 0) {
				for ($mi = 0; $mi < $mcat_limit; $mi++) {
					$mcat = $app->input->get('mcats' . $mi, 'array', 'ARRAY');
					if (count($mcat)) {
						$mc = intval(str_ireplace('p', '', end($mcat)));
						if ($mc > 0) {
							$mcats_list .= $mc . ',';
						}
					}
				}
			}



			if ($mcats_list) {
				$mcats_list .= $row->cat_id;
				$mcat_where = ' IN (' . $mcats_list . ')';
			} else {
				$mcat_where = ' = ' . $row->cat_id . ' ';
			}


			$query = "SELECT f.* FROM #__djcf_fields f "
				. "LEFT JOIN #__djcf_fields_xref fx ON f.id=fx.field_id "
				. "WHERE fx.cat_id  " . $mcat_where . " GROUP BY fx.field_id "
				. "UNION "
				. "SELECT f.* FROM #__djcf_fields f "
				. "LEFT JOIN #__djcf_fields_xref fx ON f.id=fx.field_id "
				. "WHERE f.source=1 AND f.edition_blocked=0 ";
			$db->setQuery($query);
			$fields_list = $db->loadObjectList();

			$a_tags_cf = '';
			if ((int) $par->get('allow_htmltags_cf', '0')) {
				$allowed_tags_cf = explode(';', $par->get('allowed_htmltags_cf', ''));
				for ($a = 0; $a < count($allowed_tags_cf); $a++) {
					$a_tags_cf .= '<' . $allowed_tags_cf[$a] . '>';
				}
			}

			$ins = 0;
			if (count($fields_list) > 0) {
				$query = "INSERT INTO #__djcf_fields_values(`field_id`,`item_id`,`value`,`value_date`,`value_date_to`) VALUES ";
				foreach ($fields_list as $fl) {
					if ($fl->type == 'checkbox') {
						if (isset($_POST[$fl->name])) {
							$field_v = $_POST[$fl->name];
							$f_value = ';';
							for ($fv = 0; $fv < count($field_v); $fv++) {
								$f_value .= $field_v[$fv] . ';';
							}

							$query .= "('" . $fl->id . "','" . $row->id . "','" . $db->escape($f_value) . "','',''), ";
							$ins++;
						}
					} else if ($fl->type == 'date') {
						if (isset($_POST[$fl->name])) {
							$f_var = JRequest::getVar($fl->name, '', '', 'string');
							$query .= "('" . $fl->id . "','" . $row->id . "','','" . $db->escape($f_var) . "',''), ";
							$ins++;
						}
					} else if ($fl->type == 'date_from_to') {
						if (isset($_POST[$fl->name]) || isset($_POST[$fl->name . '_to'])) {
							$f_var = JRequest::getVar($fl->name, '', '', 'string');
							$f_var_to = JRequest::getVar($fl->name . '_to', '', '', 'string');
							$query .= "('" . $fl->id . "','" . $row->id . "','','" . $db->escape($f_var) . "','" . $db->escape($f_var_to) . "'), ";
							$ins++;
						}
					} else if ($fl->type == 'date_min_max') {
						if (isset($_POST[$fl->name . '_start']) || isset($_POST[$fl->name . '_end'])) {
							$f_var_start = JRequest::getVar($fl->name . '_start', '', '', 'string');
							$f_var_end = JRequest::getVar($fl->name . '_end', '', '', 'string');
							$f_var_all_day = isset($_POST[$fl->name . '_all_day']) ? '1' : '0';
							$query2 = "INSERT INTO #__djcf_fields_values(`field_id`,`item_id`,`value`,`value_date`,`value_date_start`,`value_date_end`,`all_day`) VALUES ";
							$query2 .= "('" . $fl->id . "','" . $row->id . "','','','" . $db->escape($f_var_start) . "','" . $db->escape($f_var_end) . "','" . $f_var_all_day . "');";
							$db->setQuery($query2);
							$db->query();
						}
					} else {
						if (isset($_POST[$fl->name])) {
							if ($a_tags_cf) {
								$f_var = JRequest::getVar($fl->name, '', '', 'string', JREQUEST_ALLOWRAW);
								$f_var = strip_tags($f_var, $a_tags_cf);
							} else {
								$f_var = JRequest::getVar($fl->name, '', '', 'string');
							}
							$query .= "('" . $fl->id . "','" . $row->id . "','" . $db->escape($f_var) . "','',''), ";
							$ins++;
						}
					}
				}
			}
			if ($ins > 0) {
				$query = substr($query, 0, -2) . ';';
				$db->setQuery($query);
				$db->query();
			}


			$query = "SELECT f.* FROM #__djcf_fields f "
				. "LEFT JOIN #__djcf_fields_xref fx ON f.id=fx.field_id "
				. "WHERE fx.cat_id  = " . $row->cat_id . " AND f.in_buynow=1 ";
			$db->setQuery($query);
			$fields_list = $db->loadObjectList();

			$ins = 0;
			if (count($fields_list) > 0) {
				$query = "INSERT INTO #__djcf_fields_values_sale(`item_id`,`quantity`,`options`) VALUES ";
				$bn_quantity = JRequest::getVar('bn-quantity', array());
				$quantity_total = 0;
				foreach ($fields_list as &$fl) {
					$fl->bn_values = JRequest::getVar('bn-' . $fl->name, array());
				}

				$bn_options = array();
				for ($q = 0; $q < count($bn_quantity); $q++) {
					if ($bn_quantity[$q] == '' || $bn_quantity[$q] == 0) {
						continue;
					}
					$bn_option = array();
					$bn_option['quantity'] = $bn_quantity[$q];
					$bn_option['options'] = array();
					$quantity_total = $quantity_total + $bn_quantity[$q];
					foreach ($fields_list as &$fl) {
						if ($fl->bn_values[$q]) {
							$bn_opt = array();
							$bn_opt['id'] = $fl->id;
							$bn_opt['name'] = $fl->name;
							$bn_opt['label'] = $fl->label;
							$bn_opt['value'] = $fl->bn_values[$q];
							$bn_option['options'][] = $bn_opt;
						}
					}
					if (count($bn_option['options'])) {
						$bn_options[] = $bn_option;
					}
				}

				if (count($bn_options)) {
					foreach ($bn_options as $opt) {
						$query .= "('" . $row->id . "','" . $opt['quantity'] . "','" . $db->escape(json_encode($opt['options'])) . "'), ";
						$ins++;
					}

					if ($ins) {
						$query = substr($query, 0, -2) . ';';
						$db->setQuery($query);
						$db->query();

						$query = "UPDATE #__djcf_items SET quantity=" . $quantity_total . " WHERE id=" . $row->id . " ";
						$db->setQuery($query);
						$db->query();
						$row->quantity = $quantity_total;
					}
				}
			}

			if (empty($row->id)) throw new Exception("Appeal does not saved");
			
			$result = self::mapItem($row);

			$query = "SELECT id, path, name, ext FROM #__djcf_images WHERE item_id=" . $row->id . " AND type='item' AND fromUser=0 ";
			$db->setQuery($query);
			$result->photos = array_map("self::mapImage", $db->loadObjects());

			$this->plugin->setResponse($result);
		} finally {
			ob_end_clean();
		}
	}

	private static function mapImage($img) {
		$newImg = new \stdClass;
		$newImg->id = $img->id;
		$newImg->related_path = "$img->path$img->name.$img->ext";
		return $newImg;
	}

	private static function mapItem($item)
	{
		$mapped = new stdClass;

		$supportedFields = [
			(object)["name" => "id"],
			(object)["name" => "name"],
			(object)["name" => "description"],
			(object)["name" => "cat_id"],
			(object)["name" => "type_id"],
			(object)["name" => "user_id"],
			(object)["name" => "answer"],
			(object)["name" => "date_answer", "type" => "date"],
			(object)["name" => "date_start", "type" => "date"],
			(object)["name" => "region_id"],
			(object)["name" => "address"],
			(object)["name" => "latitude"],
			(object)["name" => "longitude"],
		];

		foreach($supportedFields as $field) {
			$fieldName = $field->name;
			if (isset($item->$fieldName) && !empty($item->$fieldName)) {
				if (isset($field->type)) {
					switch($field->type) {
						case "date": 
							$mapped->$fieldName = strtotime($item->$fieldName);
							break;
						default:
							$mapped->$fieldName = $item->$fieldName;
							break;	
					}					
				} else $mapped->$fieldName = $item->$fieldName;
			} else $mapped->$fieldName = null;
		}
		return $mapped;
	}
}