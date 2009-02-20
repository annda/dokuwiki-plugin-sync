<?php
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'admin.php');

require_once(DOKU_INC.'inc/IXR_Library.php');

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_sync extends DokuWiki_Admin_Plugin {

    var $profiles = array();
    var $profno = '';

    function admin_plugin_sync(){
        $this->_profileLoad();

        $this->profno = preg_replace('/[^0-9]+/','',$_REQUEST['no']);
    }



    /**
     * return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
    }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() {
        return 1020;
    }

    /**
     * handle user request
     */
    function handle() {
        if(isset($_REQUEST['p']) && is_array($_REQUEST['p'])){
            if($this->profno === '') $this->profno = count($this->profiles);
            $this->profiles[$this->profno] = $_REQUEST['p'];
            $this->_profileSave();
        }
    }

    /**
     * output appropriate html
     */
    function html() {
        if($_POST['sync'] && $this->profno!==''){
            // do the sync
            $this->_sync($this->profno,
                         $_POST['sync'],
                         (int) $_POST['lnow'],
                         (int) $_POST['rnow']);
        }elseif($_REQUEST['startsync'] && $this->profno!==''){
            // get sync list
            list($lnow,$rnow) = $this->_getTimes($this->profno);
            if($lnow){
                $list = $this->_getSyncList($this->profno,$rnow);
            }else{
                $list = array();
            }
            if(count($list)){
                $this->_directionForm($this->profno,$list,$lnow,$rnow);
            }else{
                echo $this->locale_xhtml('nochange');
            }
        }else{
            echo $this->locale_xhtml('intro');

            echo '<div class="sync_left">';
            $this->_profilelist($this->profno);
            if($this->profno !=='' ){
                echo '<br />';
                $this->_profileView($this->profno);
            }
            echo '</div>';
            echo '<div class="sync_right">';
            $this->_profileform($this->profno);
            echo '</div>';
        }
    }

    /**
     * Load profiles from serialized storage
     */
    function _profileLoad(){
        global $conf;
        $profiles = $conf['metadir'].'/sync.profiles';
        if(file_exists($profiles)){
            $this->profiles = unserialize(io_readFile($profiles,false));
        }
    }

    /**
     * Save profiles to serialized storage
     */
    function _profileSave(){
        global $conf;
        $profiles = $conf['metadir'].'/sync.profiles';
        io_saveFile($profiles,serialize($this->profiles));
    }

    /**
     * Check connection for choosen profile and display last sync date.
     */
    function _profileView($no){
        global $conf;

        $client = new IXR_Client($this->profiles[$no]['server']);
        $client->user = $this->profiles[$no]['user'];
        $client->pass = $this->profiles[$no]['pass'];
        $ok = $client->query('dokuwiki.getVersion');
        $version = '';
        if($ok) $version = $client->getResponse();

        echo '<form action="" method="post">';
        echo '<input type="hidden" name="no" value="'.hsc($no).'" />';
        echo '<fieldset><legend>'.$this->getLang('syncstart').'</legend>';
        if($version){
            echo '<p>'.$this->getLang('remotever').' '.hsc($version).'</p>';
            if($this->profiles[$no]['ltime']){
                echo '<p>'.$this->getLang('lastsync').' '.strftime($conf['dformat'],$this->profiles[$no]['ltime']).'</p>';
            }else{
                echo '<p>'.$this->getLang('neversync').'</p>';
            }
            echo '<input name="startsync" type="submit" value="'.$this->getLang('syncstart').'" class="button" />';
        }else{
            echo '<p class="error">'.$this->getLang('noconnect').'<br />'.hsc($client->getErrorMessage()).'</p>';
        }
        echo '</fieldset>';
        echo '</form>';
    }

    /**
     * Dropdown list of available sync profiles
     */
    function _profilelist($no=''){
        echo '<form action="" method="post">';
        echo '<fieldset><legend>'.$this->getLang('profile').'</legend>';
        echo '<select name="no" class="edit"';
        echo '  <option value="">'.$this->getLang('newprofile').'</option>';
        foreach($this->profiles as $pno => $opts){
            $srv = parse_url($opts['server']);

            echo '<option value="'.hsc($pno).'" '.(($no!=='' && $pno == $no)?'selected="selected"':'').'>';
            if($opts['user']) echo hsc($opts['user']).'@';
            echo hsc($srv['host']);
            echo '</option>';
        }
        echo '</select>';
        echo '<input type="submit" value="'.$this->getLang('select').'" class="button" />';
        echo '</fieldset>';
        echo '</form>';
    }

    /**
     * Form to edit or create a sync profile
     */
    function _profileform($no=''){
        echo '<form action="" method="post">';
        echo '<fieldset><legend>';
        if($no !== ''){
            echo $this->getLang('edit');
        }else{
            echo $this->getLang('create');
        }
        echo '</legend>';

        echo '<input type="hidden" name="no" value="'.hsc($no).'" />';

        echo '<label for="sync__server">'.$this->getLang('server').'</label> ';
        echo '<input type="text" name="p[server]" id="sync__server" class="edit" value="'.hsc($this->profiles[$no]['server']).'" /><br />';
        echo '<samp>http://example.com/dokuwiki/lib/exe/xmlrpc.php</samp><br />';

        echo '<label for="sync__ns">'.$this->getLang('ns').'</label> ';
        echo '<input type="text" name="p[ns]" id="sync__ns" class="edit" value="'.hsc($this->profiles[$no]['ns']).'" /><br />';

        echo '<label for="sync__user">'.$this->getLang('user').'</label> ';
        echo '<input type="text" name="p[user]" id="sync__user" class="edit" value="'.hsc($this->profiles[$no]['user']).'" /><br />';

        echo '<label for="sync__pass">'.$this->getLang('pass').'</label> ';
        echo '<input type="password" name="p[pass]" id="sync__pass" class="edit" value="'.hsc($this->profiles[$no]['pass']).'" /><br />';

        echo '<input type="submit" value="'.$this->getLang('save').'" class="button" />';
        if($no !== '' && $this->profiles[$no]['ltime']){
            echo '<br /><small>'.$this->getLang('changewarn').'</small>';
        }
        echo '</fieldset>';
        echo '</form>';
    }

    /**
     * Execute the sync action and print the results
     */
    function _sync($no,&$synclist,$ltime,$rtime){
        $sum = $_REQUEST['sum'];

        echo $this->locale_xhtml('sync');
        echo '<ul class="sync">';
        $client = new IXR_Client($this->profiles[$no]['server']);
        $client->user = $this->profiles[$no]['user'];
        $client->pass = $this->profiles[$no]['pass'];

        // lock the files
        $lock = array();
        foreach((array) $synclist as $id => $dir){
            if($dir == 0) continue;
            if(checklock($id)){
                echo '<li class="error"><div class="li">';
                echo $this->getLang('lockfail').' '.hsc($id);
                echo '</div></li>';
                unset($synclist[$id]);
            }else{
                lock($id); // lock local
                $lock[] = $id;
            }
        }
        // lock remote files
        $ok = $client->query('dokuwiki.setLocks',array('lock'=>$lock,'unlock'=>array()));
        if(!$ok) die('failed RPC communication');
        $data = $client->getResponse();
        foreach((array) $data['lockfail'] as $id){
            echo '<li class="error"><div class="li">';
            echo $this->getLang('lockfail').' '.hsc($id);
            echo '</div></li>';
            unset($synclist[$id]);
        }

        // do the sync
        foreach((array) $synclist as $id => $dir){
            @set_time_limit(30);
            flush();
            if($dir == 0){
                echo '<li class="ok"><div class="li">';
                echo $this->getLang('skipped').' '.hsc($id).' ';
                echo '</div></li>';
                continue;
            }
            if($dir == -2){
                //delete local
                saveWikiText($id,'',$sum,false);
                echo '<li class="ok"><div class="li">';
                echo $this->getLang('localdel').' '.hsc($id).' ';
                echo '</div></li>';
                continue;
            }
            if($dir == -1){
                //pull
                $ok = $client->query('wiki.getPage',$id);
                if(!$ok){
                    echo '<li class="error"><div class="li">';
                    echo $this->getLang('pullfail').' '.hsc($id).' ';
                    echo hsc($client->getErrorMessage());
                    echo '</div></li>';
                    continue;
                }
                $data = $client->getResponse();
                saveWikiText($id,$data,$sum,false);
                echo '<li class="ok"><div class="li">';
                echo $this->getLang('pullok').' '.hsc($id).' ';
                echo '</div></li>';
                continue;
            }
            if($dir == 1){
                // push
                $data = rawWiki($id);
                $ok = $client->query('wiki.putPage',$id,$data,array('sum'=>$sum));
                if(!$ok){
                    echo '<li class="error"><div class="li">';
                    echo $this->getLang('pushfail').' '.hsc($id).' ';
                    echo hsc($client->getErrorMessage());
                    echo '</div></li>';
                    continue;
                }
                echo '<li class="ok"><div class="li">';
                echo $this->getLang('pushok').' '.hsc($id).' ';
                echo '</div></li>';
                continue;
            }
            if($dir == 2){
                // remote delete
                $ok = $client->query('wiki.putPage',$id,'',array('sum'=>$sum));
                if(!$ok){
                    echo '<li class="error"><div class="li">';
                    echo $this->getLang('remotedelfail').' '.hsc($id).' ';
                    echo hsc($client->getErrorMessage());
                    echo '</div></li>';
                    continue;
                }
                echo '<li class="ok"><div class="li">';
                echo $this->getLang('remotedelok').' '.hsc($id).' ';
                echo '</div></li>';
                continue;
            }
        }
        echo '</ul>';

        // unlock
        foreach((array) $synclist as $id => $dir){
            unlock($id);
        }
        $client->query('dokuwiki.setLocks',array('lock'=>array(),'unlock'=>$lock));


        // save synctime
        list($letime,$retime) = $this->_getTimes($no);
        $this->profiles[$no]['ltime'] = $ltime;
        $this->profiles[$no]['rtime'] = $rtime;
        $this->profiles[$no]['letime'] = $letime;
        $this->profiles[$no]['retime'] = $retime;
        $this->_profileSave();

        echo '<p>'.$this->getLang('syncdone').'</p>';
    }

    /**
     * Print a list of changed files and ask for the sync direction
     *
     * Tries to be clever about suggesting the direction
     */
    function _directionForm($no,$synclist,$lnow,$rnow){
        global $conf;
        global $lang;

        $ltime = (int) $this->profiles[$no]['ltime'];
        $rtime = (int) $this->profiles[$no]['rtime'];
        $letime = (int) $this->profiles[$no]['letime'];
        $retime = (int) $this->profiles[$no]['retime'];

        echo $this->locale_xhtml('list');
        echo '<form action="" method="post">';
        echo '<table class="inline">';
        echo '<input type="hidden" name="lnow" value="'.$lnow.'" />';
        echo '<input type="hidden" name="rnow" value="'.$rnow.'" />';
        echo '<input type="hidden" name="no" value="'.$no.'" />';
        echo '<tr>
                <th>'.$this->getLang('page').'</th>
                <th>'.$this->getLang('local').'</th>
                <th>&gt;</th>
                <th>=</th>
                <th>&lt;</th>
                <th>'.$this->getLang('remote').'</th>
                <th>'.$this->getLang('diff').'</th>
              </tr>';
        foreach($synclist as $id => $item){
            // check direction
            $dir = 0;
            if($ltime && $rtime){ // synced before
                if($item['remote']['rev'] > $rtime &&
                   $item['local']['rev'] <= $letime){
                    $dir = -1;
                }
                if($item['remote']['rev'] <= $retime &&
                   $item['local']['rev'] > $ltime){
                    $dir = 1;
                }
            }else{ // never synced
                if(!$item['local']['rev'] && $item['remote']['rev']){
                    $dir = -1;
                }
                if($item['local']['rev'] && !$item['remote']['rev']){
                    $dir = 1;
                }
            }

            echo '<tr>';

            echo '<td>'.hsc($id).'</td>';
            echo '<td>';
            if(!isset($item['local'])){
                echo '&mdash;';
            }else{
                echo strftime($conf['dformat'],$item['local']['rev']);
                echo ' ('.$item['local']['size'].' bytes)';
            }
            echo '</td>';

            echo '<td>';
            if(!isset($item['local'])){
                echo '<input type="radio" name="sync['.hsc($id).']" value="2" title="'.$this->getLang('pushdel').'" '.(($dir == 2)?'checked="checked"':'').' />';
            }else{
                echo '<input type="radio" name="sync['.hsc($id).']" value="1" title="'.$this->getLang('push').'" '.(($dir == 1)?'checked="checked"':'').' />';
            }
            echo '</td>';
            echo '<td>';
            echo '<input type="radio" name="sync['.hsc($id).']" value="0" title="'.$this->getLang('keep').'" '.(($dir == 0)?'checked="checked"':'').' />';
            echo '</td>';
            echo '<td>';
            if(!isset($item['remote'])){
                echo '<input type="radio" name="sync['.hsc($id).']" value="-2" title="'.$this->getLang('pulldel').'" '.(($dir == -2)?'checked="checked"':'').' />';
            }else{
                echo '<input type="radio" name="sync['.hsc($id).']" value="-1" title="'.$this->getLang('pull').'" '.(($dir == -1)?'checked="checked"':'').' />';
            }
            echo '</td>';

            echo '<td>';
            if(!isset($item['remote'])){
                echo '&mdash;';
            }else{
                echo strftime($conf['dformat'],$item['remote']['rev']);
                echo ' ('.$item['remote']['size'].' bytes)';
            }
            echo '</td>';

            echo '<td>';
            echo '<a href="'.DOKU_BASE.'lib/plugins/sync/diff.php?id='.$id.'&amp;no='.$no.'" target="_blank" class="sync_popup">'.$this->getLang('diff').'</a>';
            echo '</td>';

            echo '</tr>';
        }
        echo '</table>';
        echo '<label for="the__summary">'.$lang['summary'].'</label> ';
        echo '<input type="text" name="sum" id="the__summary" value="" class="edit" />';
        echo '<input type="submit" value="'.$this->getLang('syncstart').'" class="button" />';
        echo '</form>';
    }

    /**
     * Get the local and remote time
     */
    function _getTimes($no){
        // get remote time
        $client = new IXR_Client($this->profiles[$no]['server']);
        $client->user = $this->profiles[$no]['user'];
        $client->pass = $this->profiles[$no]['pass'];
        $ok = $client->query('dokuwiki.getTime');
        if(!$ok){
            msg('Failed to fetch remote time. '.
                $client->getErrorMessage(),-1);
            return false;
        }
        $rtime = $client->getResponse();
        $ltime = time();
        return array($ltime,$rtime);
    }

    /**
     * Get a list of changed files
     */
    function _getSyncList($no){
        global $conf;
        $list = array();
        $ns = $this->profiles[$no]['ns'];

        // get remote file list
        $client = new IXR_Client($this->profiles[$no]['server']);
        $client->user = $this->profiles[$no]['user'];
        $client->pass = $this->profiles[$no]['pass'];
        $ok = $client->query('dokuwiki.getPagelist',$ns,array('depth' => 0, 'hash' => true));
        if(!$ok){
            msg('Failed to fetch remote file list. '.
                $client->getErrorMessage(),-1);
            return false;
        }
        $remote = $client->getResponse();
        // put into synclist
        foreach($remote as $item){
            $list[$item['id']]['remote'] = $item;
            unset($list[$item['id']]['remote']['id']);
        }
        unset($remote);

        // get local file list
        $local = array();
        $dir = utf8_encodeFN(str_replace(':', '/', $ns));
        require_once(DOKU_INC.'inc/search.php');
        search($local, $conf['datadir'], 'search_allpages', array('depth' => 0, 'hash' => true), $dir);
        // put into synclist
        foreach($local as $item){
            // skip identical files
            if($list[$item['id']]['remote']['hash'] == $item['hash']){
                unset($list[$item['id']]);
                continue;
            }

            $list[$item['id']]['local'] = $item;
            unset($list[$item['id']]['local']['id']);
        }
        unset($local);

        ksort($list);
        return $list;
    }

    /**
     * show diff between the local and remote versions of the page
     */
    function _diff($id){
        $no = $this->profno;
        $client = new IXR_Client($this->profiles[$no]['server']);
        $client->user = $this->profiles[$no]['user'];
        $client->pass = $this->profiles[$no]['pass'];

        $ok = $client->query('wiki.getPage',$id);
        if(!$ok){
            echo $this->getLang('pullfail').' '.hsc($id).' ';
            echo hsc($client->getErrorMessage());
            die();
        }
        $remote = $client->getResponse();
        $local  = rawWiki($id);

        $df = new Diff(explode("\n",htmlspecialchars($local)),
                       explode("\n",htmlspecialchars($remote)));

        $tdf = new TableDiffFormatter();
        echo '<table class="diff">';
        echo '<tr>';
        echo '<th colspan="2">'.$this->getLang('local').'</th>';
        echo '<th colspan="2">'.$this->getLang('remote').'</th>';
        echo '</tr>';
        echo $tdf->format($df);
        echo '</table>';
    }
}
//Setup VIM: ex: et ts=4 enc=utf-8 :