<?php
/**
 * @Copyright Copyright (C) 2010- ... author-name
 * @license GNU/GPL http://www.gnu.org/copyleft/gpl.html
 **/


// Check to ensure this file is included in Joomla!
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport( 'joomla.plugin.plugin' );

class plgContentAutoMenu_Magic_Bincom extends JPlugin {
    
    const menuitem_link_format  = 'index.php?option=com_content&view=article&id=%d';
    const menualias_link_format = 'index.php?Itemid=%d';
    
    const all_menuitem_params = 'show_title,link_titles,show_intro,show_section,link_section,show_category,link_category,show_author,show_create_date,show_modify_date,show_item_navigation,show_readmore,show_vote,show_icons,show_pdf_icon,show_print_icon,show_email_icon,show_hits,feed_summary,page_title,show_page_title,pageclass_sfx,menu_image,secure';
    
	/**
	 * Constructor
	 *
	 * For php4 compatability we must not use the __constructor as a constructor for plugins
	 * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
	 * This causes problems with cross-referencing necessary for the observer design pattern.
	 *
	 * @param object $subject The object to observe
	 * @param object $params  The object that holds the plugin parameters
	 * @since 1.5
	 */
	function plgContentAutoMenu_Magic_Bincom( &$subject, $params ) {        
		parent::__construct( $subject, $params );
	}


    private function fatalError($error_message) {
        $mainframe = JFactory::getApplication();

        $mainframe->enqueueMessage(JText::sprintf($error_message), 'error');
        
        return false;
    }

    private function defaultComContentParams() {
        // Params Time!
        $menu_params = array();
        $all_menuitem_params = explode(',',self::all_menuitem_params);
        
        // First we load the user-defined defaults:
        foreach (explode(',', $this->params->get('menuitem_param_defaults')) as $param_default)
            if (preg_match('/^(.+)[\=](.+)$/', $param_default, $matches)) {
                $param = $matches[1];
                $value = $matches[2];
                
                $menu_params[] = sprintf("%s=%s",$param,$value);
                unset($all_menuitem_params[$param]);
            }
        
       // Now we'll declare all other menuitem params (that seem to be required) to NULL
        foreach ($all_menuitem_params as $param)
            $menu_params[] = sprintf("%s=",$param);

        return implode("\n",$menu_params);
    }
	

	private function &createMenuItem($linktype, $link, $name, $menutype, $published, $params, $componentid = 0, $menuparentid = 0, $menusublevel = 0, $menulanguage='*', $alias, $accesslvl, $menuclientid=0) {
	
	//bade wants to extend this such that when an article is created in a category that has that menu title , a submenu is created also. 
        $db   = &JFactory::getDBO();
        $menu = JTable::getInstance( 'menu');

        $menu->menutype           = $menutype;
        $menu->title              = $name;
		$menu->alias              = $alias;
        $menu->link               = $link;
        $menu->type               = $linktype;
        $menu->published          = $published;
        $menu->component_id       = $componentid; 
        $menu->client_id          = $menuclientid;
        $menu->language           = $menulanguage;
        $menu->checked_out        = 0;
		$menu->access             = $accesslvl;
        $menu->checked_out_time   = 0;
        $menu->browserNav         = 0;
        $menu->home               = 0;
        
		/*
		$menu->lft                = 0;
        $menu->rgt                = 0;
        $menu->level              = $menusublevel;
		$menu->parent_id          = $menuparentid;
		$menu->ordering           = 0;
		*/
		
        $menu->params = $params;
		/*
        // Figure out the order (Just pop this article at the end of the list):
        $menu->ordering = $menu->getNextOrder(
            "menutype = ".$db->Quote($menu->menutype).
            " AND published >= 0 AND parent_id = ".(int) $menu->parent_id
        );*/
		
		// Validate:
        if (!$menu->check())
            return NULL;
		
		// DEBUG 2 -- Added because DEBUG 1 was never reached.
		// throw new Exception ("menutype: $menutype, name: $name, link: $link published: $published, componentid: $componentid menuparentid: $menuparentid menusublevel: $menusublevel");
		
        // Save:
        if (!$menu->store())
            return NULL;
		
		$menu->moveByReference($menuparentid, 'first-child');
		
		if (!$menu->store())
			throw new Exception ("Menu could store before, why not now!");
		
        // Release any checkout status:
        $menu->checkin();
		
        // Compact the menu ordering:
        $menu->reorder( 'menutype='.$db->Quote( $menu->menutype ).' AND parent_id='.(int)$menu->parent_id );
        
        return $menu;
    }
	
    private function assignModulesToItemUsingTemplate($itemid, $templ_itemid) {
        $db = &JFactory::getDBO();
        
        $db->setQuery(
            sprintf('SELECT moduleid FROM #__modules_menu WHERE menuid = %d', (int) $templ_itemid)
        );
        
        $parents_modules = $db->loadObjectList();
    
        if ($parents_modules)
            foreach ($parents_modules as $module) {
                // Assign new module to menu alias
                $db->setQuery( 
                    sprintf( 
                    'INSERT INTO #__modules_menu SET moduleid = %d, menuid = %d',
                    (int) $module->moduleid,
                    (int) $itemid
                    )
                );
                
                if (!$db->query())
                    return false;
            }

        return true;
    }
    
	function onContentAfterSave($context, $article, $isNew) {
		$carryoutonmenuwithname = 'automenu';
		$db = &JFactory::getDBO();
		$category = null;
		$new_menuitem = null;
		$new_menualiases = array();
		
		// First let's find out the category:
		if ($article->catid) {
			$category = JTable::getInstance( 'category' );
			$category->load( $article->catid );
		}
		
		// Let's find if any menus have a title that matches the new article title / regex
		$menutype_title_matches = $this->params->get('menutype_title_matches');
		if ($menutype_title_matches) {
			$db->setQuery(
				sprintf(
					'SELECT * FROM #__menu_types WHERE title REGEXP %s',
					$db->Quote( sprintf($menutype_title_matches,$category->title) )
				)
			);
			$menutype = $db->loadObject();
			
			if ($menutype) {
				$com_articles = &JTable::getInstance( 'extension' );
				$com_articles->loadByOption( 'com_content' );
				
				if (!$com_articles)
					return $this->fatalError('Unable to load com_content component');
					
				// Found a match - let's create a menuitem
				$new_menuitem = &$this->createMenuItem(
					'component',
					sprintf(self::menuitem_link_format,$article->id), 
					$article->title, 
					$menutype->menutype,
					$article->state,
					$this->defaultComContentParams(),
					$com_articles->id
				);
				if (!$new_menuitem)
					return $this->fatalError('Unable to create article menu item');
			}
		}
		
		
		// Now try to match the article to any menu items, so we can add the article as a submenu
		if ($menutype_title_matches && $carryoutonmenuwithname) {
			$dbquery = 'SELECT * FROM #__menu WHERE title REGEXP %s' ; 
			
			if ( $carryoutonmenuwithname != 'all' ) { 
				$dbquery = $dbquery . ' and menutype = "' . $carryoutonmenuwithname . '"' ;
			}
			
			$db->setQuery(
				sprintf(
					$dbquery  ,
					$db->Quote( sprintf($menutype_title_matches,$category->title) )
				)
			);
			$menutype_tocreatesubmenu = $db->loadObject();
			
			if ($menutype_tocreatesubmenu) {
				$com_articles = $this->getComContent();
				
				if (!$com_articles)
					return $this->fatalError('Unable to load com_content component');
				
				// Found a match - let's create a menuitem
				$new_menuitem_tocreatesubmenu = &$this->createMenuItem(
					'component',
					sprintf(self::menuitem_link_format,$article->id), 
					$article->title, 
					$menutype_tocreatesubmenu->menutype,
					$article->state,
					$this->defaultComContentParams(),
					$com_articles->extension_id,
					$menutype_tocreatesubmenu->id,
					($menutype_tocreatesubmenu->level + 1),
					$article->language,
					$article->alias,
					$menutype_tocreatesubmenu->access
				);
				
				if (!$new_menuitem_tocreatesubmenu){
					return $this->fatalError('Unable to create article menu item');
				} else { 
					JError:: raiseNotice(500, "Sub Menu created on Site under the Menu: ".$menutype_tocreatesubmenu->menutype );
				} //raisenotice
			}
		}
		return true;
		
	}

	function getComContent(){
		$db = &JFactory::getDBO();
		
		$db->setQuery('SELECT * FROM #__extensions WHERE name = "com_content"');
		return $db->loadObject();
	}
}