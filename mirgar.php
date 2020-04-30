<?php
/**
 * @package API plugins
 * @copyright Copyright (C) 2009 2014 Techjoomla, Tekdi Technologies Pvt. Ltd. All rights reserved.
 * @license GNU GPLv2 <http://www.gnu.org/licenses/old-licenses/gpl-2.0.html>
 * @link http://www.techjoomla.com
*/

defined('_JEXEC') or die( 'Restricted access' );

jimport('joomla.plugin.plugin');

class plgAPIMirgar extends ApiPlugin
{
	public function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);

		$this->content_types['application/bson'] = 'bson';

		ApiResource::addIncludePath(dirname(__FILE__) . '/mirgar');
		
		/*load language file for plugin frontend*/ 
		$lang = JFactory::getLanguage(); 
		$lang->load('plg_api_mirgar', JPATH_ADMINISTRATOR,'',true);
		
		// Set the login resource to be public
		$this->setResourceAccess('login', 'public','get');
		$this->setResourceAccess('users', 'public', 'post');
		$this->setResourceAccess('config', 'public', 'get');
		$this->setResourceAccess('user', 'public', 'post');
		$this->setResourceAccess('categories', 'public', 'get');
		$this->setResourceAccess('appeal', 'public', 'get');
	}
}
