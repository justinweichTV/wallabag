<?php
/**
 * poche, a read it later open source system
 *
 * @category   poche
 * @author     Nicolas Lœuillet <support@inthepoche.com>
 * @copyright  2013
 * @license    http://www.wtfpl.net/ see COPYING file
 */

class Poche
{
    public $store;
    public $tpl;

    function __construct($storage_type)
    {
        $this->store = new $storage_type();
        $this->init();

        # installation
        if(!$this->store->isInstalled())
        {
            $this->install();
        }

        $this->saveUser();
    }

    private function init() 
    {
        # l10n
        putenv('LC_ALL=' . LANG);
        setlocale(LC_ALL, LANG);
        bindtextdomain(LANG, LOCALE); 
        textdomain(LANG); 

        # template engine
        $loader = new Twig_Loader_Filesystem(TPL);
        $this->tpl = new Twig_Environment($loader, array(
            'cache' => CACHE,
        ));
        $this->tpl->addExtension(new Twig_Extensions_Extension_I18n());

        Tools::initPhp();
        Session::init();
    }

    private function install() 
    {
        Tools::logm('poche still not installed');
        echo $this->tpl->render('install.twig', array(
            'token' => Session::getToken(),
        ));
        if (isset($_GET['install'])) {
            if (($_POST['password'] == $_POST['password_repeat']) 
                && $_POST['password'] != "" && $_POST['login'] != "") {
                # let's rock, install poche baby !
                $this->store->install($_POST['login'], Tools::encodeString($_POST['password'] . $_POST['login']));
                Session::logout();
                Tools::redirect();
            }
        }
        exit();
    }

    private function saveUser()
    {
        $_SESSION['login'] = (isset ($_SESSION['login'])) ? $_SESSION['login'] : $this->store->getLogin();
        $_SESSION['pass'] = (isset ($_SESSION['pass'])) ? $_SESSION['pass'] : $this->store->getPassword();
    }

    /**
     * Call action (mark as fav, archive, delete, etc.)
     */
    public function action($action, Url $url, $id = 0)
    {
        switch ($action)
        {
            case 'add':
                if($parametres_url = $url->fetchContent()) {
                    if ($this->store->add($url->getUrl(), $parametres_url['title'], $parametres_url['content'])) {
                        Tools::logm('add link ' . $url->getUrl());
                        $last_id = $this->store->getLastId();
                        if (DOWNLOAD_PICTURES) {
                            $content = filtre_picture($parametres_url['content'], $url->getUrl(), $last_id);
                        }
                        #$msg->add('s', _('the link has been added successfully'));
                    }
                    else {
                        #$msg->add('e', _('error during insertion : the link wasn\'t added'));
                        Tools::logm('error during insertion : the link wasn\'t added');
                    }
                }
                else {
                    #$msg->add('e', _('error during url preparation : the link wasn\'t added'));
                    Tools::logm('error during content fetch');
                }
                break;
            case 'delete':
                if ($this->store->deleteById($id)) {
                    if (DOWNLOAD_PICTURES) {
                        remove_directory(ABS_PATH . $id);
                    }
                    #$msg->add('s', _('the link has been deleted successfully'));
                    Tools::logm('delete link #' . $id);
                }
                else {
                    #$msg->add('e', _('the link wasn\'t deleted'));
                    Tools::logm('error : can\'t delete link #' . $id);
                }
                break;
            case 'toggle_fav' :
                $this->store->favoriteById($id);
                Tools::logm('mark as favorite link #' . $id);
                break;
            case 'toggle_archive' :
                $this->store->archiveById($id);
                Tools::logm('archive link #' . $id);
                break;
            case 'import':
                break;
            default:
                break;
        }
    }

    function displayView($view, $id = 0)
    {
        $tpl_vars = array();

        switch ($view)
        {
            case 'install':
                Tools::logm('install mode');
                break;
            case 'import';
                Tools::logm('import mode');
                break;
            case 'export':
                $entries = $this->store->retrieveAll();
                // $tpl->assign('export', Tools::renderJson($entries));
                // $tpl->draw('export');
                Tools::logm('export view');
                break;
            case 'config':
                Tools::logm('config view');
                break;
            case 'view':
                $entry = $this->store->retrieveOneById($id);
                if ($entry != NULL) {
                    Tools::logm('view link #' . $id);
                    $content = $entry['content'];
                    if (function_exists('tidy_parse_string')) {
                        $tidy = tidy_parse_string($content, array('indent'=>true, 'show-body-only' => true), 'UTF8');
                        $tidy->cleanRepair();
                        $content = $tidy->value;
                    }
                    $tpl_vars = array(
                        'entry' => $entry,
                        'content' => $content,
                    );
                }
                else {
                    Tools::logm('error in view call : entry is NULL');
                }
                break;
            default: # home view
                $entries = $this->store->getEntriesByView($view);
                $tpl_vars = array(
                    'entries' => $entries,
                );
                break;
        }

        return $tpl_vars;
    }

    public function updatePassword()
    {
        if (isset($_POST['password']) && isset($_POST['password_repeat'])) {
            if ($_POST['password'] == $_POST['password_repeat'] && $_POST['password'] != "") {
                if (!MODE_DEMO) {
                    Tools::logm('password updated');
                    $this->store->updatePassword(Tools::encodeString($_POST['password'] . $_SESSION['login']));
                    Session::logout();
                    Tools::redirect();
                }
                else {
                    Tools::logm('in demo mode, you can\'t do this');
                }
            }
        }
    }

    public function login($referer)
    {
        if (!empty($_POST['login']) && !empty($_POST['password'])) {
            if (Session::login($_SESSION['login'], $_SESSION['pass'], $_POST['login'], Tools::encodeString($_POST['password'] . $_POST['login']))) {
                Tools::logm('login successful');

                if (!empty($_POST['longlastingsession'])) {
                    $_SESSION['longlastingsession'] = 31536000;
                    $_SESSION['expires_on'] = time() + $_SESSION['longlastingsession'];
                    session_set_cookie_params($_SESSION['longlastingsession']);
                } else {
                    session_set_cookie_params(0);
                }
                session_regenerate_id(true);
                Tools::redirect($referer);
            }
            Tools::logm('login failed');
            Tools::redirect();
        } else {
            Tools::logm('login failed');
            Tools::redirect();
        }
    }

    public function logout()
    {
        Tools::logm('logout');
        Session::logout();
        Tools::redirect();
    }

    public function import($from)
    {
        if ($from == 'pocket') {
            $html = new simple_html_dom();
            $html->load_file('./ril_export.html');

            $read = 0;
            $errors = array();
            foreach($html->find('ul') as $ul)
            {
                foreach($ul->find('li') as $li)
                {
                    $a = $li->find('a');
                    $url = new Url($a[0]->href);
                    $this->action('add', $url);
                    if ($read == '1') {
                        $last_id = $this->store->lastInsertId();
                        $sql_update = "UPDATE entries SET is_read=~is_read WHERE id=?";
                        $params_update = array($last_id);
                        $query_update = $this->store->prepare($sql_update);
                        $query_update->execute($params_update);
                    }
                }
                # Pocket génère un fichier HTML avec deux <ul>
                # Le premier concerne les éléments non lus
                # Le second concerne les éléments archivés
                $read = 1;
            }
            logm('import from pocket completed');
            Tools::redirect();
        }
        else if ($from == 'readability') {
            # TODO finaliser tout ça ici
            $str_data = file_get_contents("readability");
            $data = json_decode($str_data,true);

            foreach ($data as $key => $value) {
                $url = '';
                foreach ($value as $key2 => $value2) {
                    if ($key2 == 'article__url') {
                        $url = new Url($value2);
                    }
                }
                if ($url != '')
                    action_to_do('add', $url);
            }
            logm('import from Readability completed');
            Tools::redirect();
        }
    }

    public function export()
    {

    }
}