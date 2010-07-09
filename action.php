<?php
/**
 * Script to search in dokuwiki documents
 *
 * @author Yaroslav Vorozhko <yaroslav@ivinco.com>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_INC.'inc/parser/parser.php');

require_once(DOKU_PLUGIN . 'action.php');
require_once(DOKU_PLUGIN . 'sphinxsearch/sphinxapi.php');
require_once(DOKU_PLUGIN . 'sphinxsearch/PageMapper.php');
require_once(DOKU_PLUGIN . 'sphinxsearch/SphinxSearch.php');
require_once(DOKU_PLUGIN . 'sphinxsearch/functions.php');


class action_plugin_sphinxsearch extends DokuWiki_Action_Plugin {
    var $_search = null;

    /**
	* return some info
	*/
    function getInfo() {
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
	}

    /**
	* Register to the content display event to place the results under it.
	*/
    /**
     * register the eventhandlers
     */
    function register(&$controller){
        $controller->register_hook('TPL_CONTENT_DISPLAY',   'BEFORE', $this, 'handle_act_unknown', array());
    }

    /**
     * If our own 'googlesearch' action was given we produce our content here
     */
    function handle_act_unknown(&$event, $param){
        global $ACT;
        global $QUERY;
        if($ACT != 'search') return; // nothing to do for us

        // we can handle it -> prevent others
        $event->stopPropagation();
        $event->preventDefault();

        
        $this->_search($QUERY,$_REQUEST['start'],$_REQUEST['prev']);
    }    

    /**
     * do the search and displays the result
     */
    function _search($query, $start, $prev) {
        global $conf;        

        $start = (int) $start;
        if($start < 0){
            $start = 0;
        }
        if(empty($prev)){
            $prev = 0;
        }

        $categories = $this->_getCategories($query);
        $keywords = $this->_getKeywords($query);

        $search = new SphinxSearch($this->getConf('host'), $this->getConf('port'), $this->getConf('index'));
        $search->setSnippetSize($this->getConf('snippetsize'));
        $search->setArroundWordsCount($this->getConf('aroundwords'));
        $search->setTitlePriority($this->getConf('title_priority'));
        $search->setBodyPriority($this->getConf('body_priority'));
        $search->setCategoriesPriority($this->getConf('categories_priority'));

        if (!empty($keywords) && empty($categories)){
            $search->setSearchAllQuery($keywords, $categories);
        } else {
            $search->setSearchAllQueryWithCategoryFilter($keywords, $categories);
        }
        $result = $search->search($start, $this->getConf('maxresults'));
        $this->_search = $search;

        if (!$result || $search->getError()){
            echo '<b>' . $search->getError() . '</b>!';
            return;
        }

        $pagesList = $search->getPages($keywords);
        
        $totalFound = $search->getTotalFound();
        if(empty($pagesList)){
            echo 'Your search - <strong>' . $query . '</strong> - did not match any documents.';
            return;
        } else {
            echo '<style type="text/css">
                div.dokuwiki .search{
                    width:1024px;
                }
                div.dokuwiki .search_snippet{
                    color:#000000;
                    margin-left:0px;
                    font-size: 13px;
                }
                div.dokuwiki .search_result{
                    width:600px;
                    float:left;
                }
                div.dokuwiki .search_result a.title{
                    font:16px Arial,Helvetica,sans-serif;
                }
                div.dokuwiki .search_result span{
                    font:12px Arial,Helvetica,sans-serif;
                }
                div.dokuwiki .search_sidebar{
                    width:300px;
                    float:right;
                    margin-right: 30px;
                }
                div.dokuwiki .search_result_row{
                    color:#000000;
                    margin-left:0px;
                    width:600px;
                    text-align:left;
                }
                div.dokuwiki .search_result_row_child{
                    color:#000000;
                    margin-left:30px;
                    width:600px;
                    text-align:left;
                }
                div.dokuwiki .hide{
                    display:none;
                }
                div.dokuwiki .search_cnt{
                    color:#909090;
                    font:12px Arial,Helvetica,sans-serif;
                }
                div.dokuwiki .search_nmsp{
                    font-size: 10px;
                }
                div.dokuwiki .sphinxsearch_nav{
                    clear:both;
                }
                </style>
                <script type="text/javascript">
function sh(id)
{
    var e = document.getElementById(id);
    if(e.style.display == "block")
        e.style.display = "none";
    else
        e.style.display = "block";
}
</script>            
';

            echo '<h2>Found '.$totalFound . ($totalFound == 1  ? ' match ' : ' matches ') . ' for query "' . hsc($query).'"</h2>';
            echo '<div class="search">';
            echo '<div class="search_result">';
            // printout the results
            $pageListGroupByPage = array();
            foreach ($pagesList as $row) {
                $page = $row['page'];
                if(!isset ($pageListGroupByPage[$page])){
                    $pageListGroupByPage[$page] = $row;
                } else {
                    $pageListGroupByPage[$page]['subpages'][] = $row;
                }
            }
            foreach ($pageListGroupByPage as $row) {
                $this->_showResult($row, $keywords, false);
                if(!empty($row['subpages'])){
                    echo '<div id="more'.$row['page'].'" class="hide">';
                    foreach($row['subpages'] as $sub){
                        $this->_showResult($sub, $keywords, true);
                    }
                    echo '</div>';
                }
                
            }
            echo '</div>';
            echo '<div class="search_sidebar">';
            printNamespacesNew($this->_getMatchingPagenames($keywords, $categories));
            echo '</div>';
            echo '<div class="sphinxsearch_nav">';
            if ($start > 1){
                if(false !== strpos($prev, ',')){
                    $prevArry = explode(",", $prev);
                    $prevNum = $prevArry[count($prevArry)-1];
                    unset($prevArry[count($prevArry)-1]);
                    $prevString = implode(",", $prevArry);
                } else {
                    $prevNum = 0;
                }

                echo $this->external_link(wl('',array('do'=>'search','id'=>$query,'start'=>$prevNum, 'prev'=>$prevString),'false','&'),
                                          'prev','wikilink1 gs_prev',$conf['target']['interwiki']);                
            }
            echo ' ';
            
            //if($start + $this->getConf('maxresults') < $totalFound){
                //$next = $start + $this->getConf('maxresults');
            if($start + $search->getOffset()< $totalFound){
                $next = $start + $search->getOffset();
                if($start > 1){
                    $prevString = $prev.','.$start;
                }
                echo $this->external_link(wl('',array('do'=>'search','id'=>$query,'start'=>$next,'prev'=>$prevString),'false','&'),
                                          'next','wikilink1 gs_next',$conf['target']['interwiki']);
            }
            echo '</div>';

            echo '</div>';
        }

        
    }

    function _showResult($row, $keywords, $subpages = false)
    {
        $page = $row['page'];
        $bodyExcerpt = $row['bodyExcerpt'];
        $titleTextExcerpt = $row['titleTextExcerpt'];
        $hid = $row['hid'];

        $metaData = p_get_metadata($page);

        if (!empty($titleTextExcerpt)){
            $titleText = $titleTextExcerpt;
        } elseif(!empty($row['title_text'])){
            $titleText = $row['title_text'];
        } elseif(!empty($metaData['title'])){
            $titleText = hsc($metaData['title']);
        } else {
            $titleText = hsc($page);
        }

        $namespaces = getNsLinks($page, $keywords, $this->_search);
        $href = !empty($hid) ? (wl($page).'#'.$hid) : wl($page);

        if($subpages){
            echo '<div class="search_result_row_child">';
        } else {
            echo '<div class="search_result_row">';
        }

        echo '<a class="wikilink1 title" href="'.$href.'" title="" >'.$titleText.'</a><br/>';
        echo '<div class="search_snippet">';
        echo strip_tags($bodyExcerpt, '<b>,<strong>');
        echo '</div>';
        $sep=':';
        $i = 0;
        echo '<span class="search_nmsp">';
        foreach ($namespaces as $name){
            $link = $name['link'];
            $pageTitle = $name['title'];
            tpl_link($link, $pageTitle);
            if ($i++ < count($namespaces)-1){
                echo $sep;
            }
        }
        if (!empty($hid)){
            echo '#'.$hid;
        }
        echo '</span>';

        if (!empty($metaData['last_change']['date'])){
            echo '<span class="search_cnt"> - Last modified '.date("Y-m-d H:i",$metaData['last_change']['date']).'</span> ';
        } else if (!empty($metaData['date']['created'])){
            echo '<span class="search_cnt"> - Last modified '.date("Y-m-d H:i",$metaData['date']['created']).'</span> ';
        }

        if(!empty($metaData['last_change']['user'])){
            echo '<span class="search_cnt">by '.$metaData['last_change']['user'].'</span> ';
        } else if(!empty($metaData['creator'])){
            echo '<span class="search_cnt">by '.$metaData['creator'].'</span> ';
        }
        
        if (!empty($row['subpages'])){
            echo '<br />';
            echo '<div style="text-align:right;font:12px Arial,Helvetica,sans-serif;text-decoration:underline;"><a href="javascript:void(0)" onClick="sh('."'more".$page."'".');" >More matches in this document</a></div>';
        }else {
            echo '<br />';
        }
        echo '<br />';
        echo '</div>';
    }

     function searchform(){
          global $lang;
          global $ACT;
          global $QUERY;

          // don't print the search form if search action has been disabled
          if (!actionOk('search')) return false;

          print '<form action="'.wl().'" accept-charset="utf-8" class="search" id="dw__search"><div class="no">';
          print '<input type="hidden" name="do" value="search" />';
          print '<input type="text" ';
          if($ACT == 'search') print 'value="'.htmlspecialchars($QUERY).'" ';
          print 'id="qsearch__in" accesskey="f" name="id" class="edit" title="[ALT+F]" />';
          print '<input type="submit" value="'.$lang['btn_search'].'" class="button" title="'.$lang['btn_search'].'" />';
          print '</div></form>';
          return true;
    }

    function _getCategories($query)
    {
        $categories = '';
        $query = urldecode($query);
        if (false !== ($pos = strpos($query, "@categories"))){;
            $categories = substr($query, $pos + strlen("@categories"));
        }
        return trim($categories);
    }

    function _getKeywords($query)
    {
        $keywords = $query;
        $query = urldecode($query);
        if (false !== ($pos = strpos($query, "-@categories"))){;
            $keywords = substr($keywords, 0, $pos);
        }else if (false !== ($pos = strpos($query, "@categories"))){;
            $keywords = substr($keywords, 0, $pos);
        }
        return trim($keywords);
    }

    function _getMatchingPagenames($keywords, $categories)
    {
        $this->_search->setSearchCategoryQuery($keywords, $categories);
        $res = $this->_search->search(0, 10);
        if (!$res){
            return false;
        }
        $pageIds = $this->_search->getPagesIds();

        $matchPages = array();
        foreach($pageIds as $page){
            $matchPages[$page['page']] = $page['hid'];
        }
        return array_unique($matchPages);
    }
}

?>
