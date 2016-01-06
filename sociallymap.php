<?php
/*
Plugin Name: Sociallymap
Plugin URI: https://github.com/alhenaconseil/wordpress-sociallymap
Description: A plugin that let the sociallymap users post on their blog from their mapping
Version: 1.0
Author: Sociallymap
Author URI: http://www.sociallymap.com/
License: MIT
*/

require_once(plugin_dir_path(__FILE__).'includes/Templater.php');
require_once(plugin_dir_path(__FILE__).'includes/DbBuilder.php');
require_once(plugin_dir_path(__FILE__).'includes/Publisher.php');
require_once(plugin_dir_path(__FILE__).'includes/Requester.php');
require_once(plugin_dir_path(__FILE__).'includes/Logger.php');
require_once(plugin_dir_path(__FILE__).'includes/MockRequester.php');
require_once(plugin_dir_path(__FILE__).'includes/FileDownloader.php');
require_once(plugin_dir_path(__FILE__).'includes/MediaWordpressManager.php');
require_once(plugin_dir_path(__FILE__).'includes/GithubUpdater.php');
require_once(plugin_dir_path(__FILE__).'includes/SociallymapController.php');
require_once(plugin_dir_path(__FILE__).'includes/exception/fileDownloadException.php');
require_once(plugin_dir_path(__FILE__).'models/EntityCollection.php');
require_once(plugin_dir_path(__FILE__).'models/Entity.php');
require_once(plugin_dir_path(__FILE__).'models/Option.php');
require_once(plugin_dir_path(__FILE__).'models/ConfigOption.php');
require_once(plugin_dir_path(__FILE__).'models/Published.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');


class SociallymapPlugin
{
    private $wpdb;
    private $templater;
    private $controller;
    private $config_default_value;
    private $link_canononical;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $environment = '';

        $_ENV["URL_SOCIALLYMAP"] = [
            "prod"    => "http://app.sociallymap.com",
            "staging" => "http://app.sociallymap-staging.com",
            "dev"     => "http://app.sociallymap.local",
        ];

        // DEV MOD : Active mock requester
        $_ENV["ENVIRONNEMENT"] = "prodpor";

        $this->templater = new Templater();
        $this->controller = new SociallymapController();

        $configsOption = new ConfigOption();
        // $this->config_default_value = $configsOption->getConfig();

        $this->link_canononical = "";

        $builder = new DbBuilder();
        $builder->dbInitialisation();

        if (array_key_exists('sociallymap_postRSS', $_POST)    ||
            array_key_exists('sociallymap_deleteRSS', $_POST)  ||
            array_key_exists('sociallymap_updateRSS', $_POST)  ||
            array_key_exists('sociallymap_updateConfig', $_POST) ) {
                add_action('admin_menu', [$this, 'entityManager']);
        }

        // todo comment routing system on all code
        add_action('init', [$this, 'rewriteInit']);
        add_action('template_redirect', [$this, 'redirectIntercept']);

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_menu', [$this, 'githubConfiguration']);
        // add_action('the_post', [$this, "rewriteCanonical"]);
        add_filter('the_content', [$this, "prePosting"]);

        add_filter('init', [$this, "initialization"]);
        // add_action('plugins_loaded', [$this, 'surcharge']);
    }

    public function surcharge()
    {
        Logger::error('TEST MAIL');
        $resultEmail = wp_mail('jean-baptiste@alhena-conseil.com', 'Wordpress plugin : Erreur critique', 'test mail');
        echo("mail result : ".$resultEmail);
        die();
    }

    public function rewriteCanonical($content)
    {
        $entityObject = new Entity();

        if (empty($content)) {
            Logger::alert('Rewrite link canonical : No content in entry');
            return false;
        }

        // Search entity and look canonical option
        $patternEntityId = "#data-entity-id='([0-9]+)'#";
        preg_match($patternEntityId, $content, $matches);
        if (isset($matches[1])) {
            $idSelect = $matches[1];
        } else {
            Logger::alert('Rewrite link canonical : No found entity');
            return false;
        }


        $patternUrl = "#data-article-url='(.+)'#";
        preg_match($patternUrl, $content, $matches);
        if (isset($matches[1])) {
            $entityUrl = $matches[1];
        } else {
            Logger::alert('Rewrite link canonical : No found url in content');
            return false;
        }

        $entityPicked = $entityObject->getById($idSelect);

        foreach ($entityPicked->options as $key => $value) {
            if ($value->options_id == '4') {
                $link_canonical = $value->value;
            }
        }

        // entity unknown
        if (empty($entityPicked)) {
            Logger::alert('Rewrite link canonical : Entity Unknown');
            return false;
        }

        if ($link_canonical) {
            // replace the default WordPress canonical URL function with your own
            $this->link_canononical = $entityUrl;
        }
        return $content;
    }

    public function initialization()
    {
        $this->loadAssets(true);

        remove_action('wp_head', 'rel_canonical');
        // add_action('wp_head', [$this, 'rewriteCanonical']);
        add_action('wp_head', [$this, 'customRelCanonical']);
        add_action('wp_head', [$this, 'noIndexRef']);

        if ($_ENV['ENVIRONNEMENT'] == "dev") {
            $collector = new EntityCollection();
            $entity = $collector->getByEntityId('56571e787c5a008811840ff4');

            $this->manageMessages($entity);
        }
    }

    public static function install()
    {
        global $wp_rewrite;

        self::addRewriteRules();

        $wp_rewrite->flush_rules();
    }

    public function redirectIntercept()
    {
        global $wp_query;

        if ($wp_query->get('sociallymap-plugin')) {
            Logger::info('Intercept message in plugin', $_POST);

            // We don't have the right parameters
            if (!isset($_POST['entityId']) || !isset($_POST['token'])) {
                header("HTTP/1.0 400 Bad Request");
                exit;
            }

            $collector = new EntityCollection();
            $entity = $collector->getByEntityId($_POST['entityId']);


            // Context : Testing connection between sociallymap and wordpress plugin
            if ($_POST['token'] == "connection-test") {
                header('Content-Type: application/json');
                if (empty($entity)) {
                    header("HTTP/1.0 404 Not Found");
                    exit(json_encode([
                        'error' => "entityId inconnu"
                    ]));
                } else {
                    header("HTTP/1.0 200 OK");
                    exit(json_encode([
                        'message' => "ok"
                    ]));
                }
            }

            // This entity not exists
            if (empty($entity)) {
                header("HTTP/1.0 404 Not Found");
                exit;
            }

            // Try to retrieve the pending messages
            if ($this->manageMessages($entity) == false) {
                header("HTTP/1.0 502 Bad gateway");
                // print_r($_POST);
                Logger::error("The plugin can't ping to sociallymap");
            } else {
                header("HTTP/1.0 200 OK");
                exit(json_encode([
                    'message' => "ok"
                ]));
            }

        }
    }

    public function rewriteInit()
    {
        add_rewrite_tag('%sociallymap-plugin%', '1');
        $this->addRewriteRules();
    }

    public static function addRewriteRules()
    {
        add_rewrite_rule('sociallymap', 'index.php?sociallymap-plugin=1', 'top');
    }

    public function noIndexRef()
    {
        global $post;

        if (!is_single()) {
            return false;
        }

        $entityObject = new Entity();

        // get entity ID
        $pattern = "#data-entity-id='([0-9]+)'#";
        preg_match($pattern, $post->post_content, $matches);
        if (isset($matches[1])) {
            $idSelect = $matches[1];
        } else {
            Logger::alert('noIndex option : No found entity attribute in content');

            return false;
        }

        // id unknown
        if (empty($idSelect)) {
            Logger::alert('noIndex option : No found entity in bdd');
            return false;
        }

        $entityPicked = $entityObject->getById($idSelect);
        $noIndex = 0;
        $follow = 1;

        if ($entityPicked) {

            foreach ($entityPicked->options as $key => $value) {
                if ($value->options_id == '7') {
                    $noIndex = $value->value;
                }
                if ($value->options_id == '8') {
                    $follow = !$value->value;
                }
            }

            // @todo manage conditions
            $contentArr = [];
            if ($noIndex) {
                $contentArr[] = 'noIndex';
            }
            if ($follow) {
                $contentArr[] = 'follow';
            }

            echo '<meta name="robots" content="' . implode(', ', $contentArr) . '">';

        }

        // if ($noIndex == 1 && $noFollow == 1) {
        //     echo ('<meta name="robots" content="noIndex,follow">');
        // } elseif ($noIndex == 0 && $noFollow == 1) {
        //     echo ('<meta name="robots" content="follow">');
        // } elseif ($noIndex == 1 && $noFollow == 0) {
        //     echo ('<meta name="robots" content="noIndex">');
        // }
    }

    public function customRelCanonical()
    {
        global $post;

        if (is_single() && $this->link_canononical != "") {
            $this->rewriteCanonical($post->post_content);
            echo '<link rel="canonical" href="'.$this->link_canononical.'" />';
        }
    }

    public function prePosting($content)
    {
        global $post;

        $entityObject = new Entity();
        $link_canonical = false;

        $pattern = "#data-entity-id='([0-9]+)'#";
        $returnSearch = preg_match($pattern, $content, $matches);

        if ($returnSearch != 1) {
            return $content;
        }

        if (isset($matches[1])) {
            $idSelect = $matches[1];
        } else {
            return $content;
        }

        // id unknown
        if (!isset($idSelect) || empty($idSelect)) {
            return $content;
        }

        $entityPicked = $entityObject->getById($idSelect);

        // entity unknown
        if (!isset($entityPicked) || empty($entityPicked)) {
            return $content;
        }

        foreach ($entityPicked->options as $key => $value) {
            if ($value->options_id == '2') {
                $display_type = $value->value;
            }

            if ($value->options_id == '4') {
                $link_canonical = $value->value;
            }
        }


        $content = preg_replace("#data-display-type#", 'data-display-type="'.$display_type.'"', $content);

        return $content;
    }

    public function addAdminMenu()
    {
        add_menu_page(
            'Sociallymap publisher',
            'Sociallymap',
            'manage',
            'sociallymap-publisher',
            function () {
                $this->loadTemplate("documentation");
            },
            plugin_dir_url(__FILE__).'assets/images/icon.png'
        );

        add_submenu_page(
            'sociallymap-publisher',
            'Mes entités',
            'Mes entités',
            'manage_options',
            'sociallymap-rss-list',
            function () {
                $this->loadTemplate("listEntities");
            }
        );

        add_submenu_page(
            'sociallymap-publisher',
            'Ajouter une entité',
            'Ajouter une entité',
            'manage_options',
            'sociallymap-rss-add',
            function () {
                $this->loadTemplate("addEntity");
            }
        );

        add_submenu_page(
            'sociallymap-publisher',
            'Documentation',
            'Documentation',
            'manage_options',
            'sociallymap-documentation',
            function () {
                $this->loadTemplate("documentation");
            }
        );

        add_submenu_page(
            null,
            'edit entity',
            'Editer lien',
            'manage_options',
            'sociallymap-rss-edit',
            function () {
                $this->loadTemplate("editEntity");
            }
        );
    }

    public function manageMessages($entity)
    {
        $requester      = new Requester();
        $publisher      = new Publisher();
        $config         = new ConfigOption();
        $entityObject   = new Entity();
        $downloader     = new FileDownloader();
        $published      = new Published();
        $summary        = "";
        $title          = "";
        $readmoreLabel  = "";

        $configs = $config->getConfig();

        // get author id
        $author = $entity->author_id;

        // The entity is not active
        if (!$entity->activate) {
            exit;
        }

        // Retrieve the entity categories
        $entityListCategory = [];
        $readmoreLabel = "";

        foreach ($entity->options as $key => $value) {
            if ($value->options_id == 1) {
                $entityListCategory[] = $value->value;
            }

            if ($value->options_id == 2) {
                $entityDisplayType = $value->value;
            }

            if ($value->options_id == 3) {
                $entityPublishType = $value->value;
            }

            if ($value->options_id == 5) {
                $entityImage = $value->value;
            }

            if ($value->options_id == 6) {
                $readmoreLabel = $value->value;
            }
        }

        // Try request to sociallymap on response
        try {
            if ($_ENV["ENVIRONNEMENT"] === 'dev') {
                $requester    = new MockRequester();
                $jsonData = $requester->getMessages();
            } else {
                $jsonData = $requester->launch($_POST['entityId'], $_POST['token'], $_POST['environment']);
            }

            if (empty($jsonData)) {
                throw new Exception('No data returned from request', 1);
                exit();
            }

            Logger::info('See return data', $jsonData);

            foreach ($jsonData as $key => $value) {
                $summary = "";
                $contentArticle = "";
                $readmore = '';

                // Check Link object existing
                if (isset($value->link)) {
                    // Check if Title existing
                    if (!empty($value->link->title)) {
                        $title = $value->link->title;
                    }

                    if (!empty($value->link->summary)) {
                        $summary = $value->link->summary;
                    }

                    // Check if Link URL existing
                    if (!empty($value->link->url)) {
                        $readmoreLabel = stripslashes($readmoreLabel);

                        $readmore = $this->templater->loadReadMore(
                            $value->link->url,
                            $entityDisplayType,
                            $entity->id,
                            $readmoreLabel
                        );
                    } else {
                        Logger::alert('This article not contain url');
                    }
                }

                $contentArticle = $summary;
                // add readmore to content if $readmore is not empty
                if ($readmore != '') {
                    $contentArticle .= $readmore;
                }

                $imageTag = '';
                $imageSrc = '';

                // Check if article posted
                $canBePublish = true;
                $messageId = $value->guid;
                if ($published->isPublished($messageId)) {
                    $contextMessageId = '(id message='.$messageId.')';
                    Logger::alert('Message of sociallymap existing, so he is not publish', $contextMessageId);
                    $canBePublish = false;
                    continue;
                }

                $pathTempory = plugin_dir_path(__FILE__).'tmp/';
                // Check if Media object exist
                if (isset($value->media) && $value->media->type == "photo") {
                    try {
                        $returnDownload = $downloader->download($value->media->url, $pathTempory);
                        $filename = $returnDownload['filename'];
                        $fileExtension = $returnDownload['extension'];

                    } catch (fileDownloadException $e) {
                        Logger::error('error download'.$e);
                    }

                    $mediaManager = new MediaWordpressManager();
                    $imageSrc = $mediaManager->integrateMediaToWordpress($filename, $fileExtension);


                    // WHEN NO ERROR : FORMAT
                    if (gettype($imageSrc) == "string") {
                        $imageTag = '<img class="aligncenter" src="'.$imageSrc.'" alt="">';
                    } else {
                        $imageTag = '';
                    }
                } elseif (isset($value->link) && !empty($value->link->thumbnail) && $value->link->thumbnail != " ") {
                    // Check if Image thumbnail existing
                    try {
                        $returnDownload = $downloader->download($value->link->thumbnail, $pathTempory);
                        $filename = $returnDownload['filename'];
                        $fileExtension = $returnDownload['extension'];
                    } catch (fileDownloadException $e) {
                        Logger::error('error download'.$e);
                    }

                    $mediaManager = new MediaWordpressManager();
                    $imageSrc = $mediaManager->integrateMediaToWordpress($filename, $fileExtension);

                    // Create the img tag
                    if (gettype($imageSrc) == "string") {
                        $imageTag = '<img class="aligncenter" src="'.$imageSrc.'" alt="">';

                    } else {
                        $imageTag = '';
                    }
                }

                // check if video exist
                $downloadVideo = false;
                if (isset($value->media) && $value->media->type == "video") {
                    $returnDownload = $downloader->download($value->media->url, $pathTempory);
                    $filename = $returnDownload['filename'];
                    $fileExtension = $returnDownload['extension'];
                    $mediaManager = new MediaWordpressManager();
                    $videoSrc = $mediaManager->integrateMediaToWordpress($filename, $fileExtension);

                    $mediaVideo = '<video class="sm-video-display" controls>
                    <source src="'.$videoSrc.'" type="video/mp4">
                    <div class="sm-video-nosupport"></div>
                    </video>';
                    $contentArticle .= $mediaVideo;
                    Logger::info("download VIDEO", $videoSrc);
                }

                // If imageTag is '' so is false else $isDownload is true
                $isDownloaded = ($imageTag !== '');


                // Attach image accordingly to options
                $imageAttachment = '';
                if ($isDownloaded) {
                    // Add image in the post content
                    if (in_array($entityImage, ['content', 'both'])) {
                        $contentArticle = $imageTag . $contentArticle;
                    }
                    // Add image as featured image
                    if (in_array($entityImage, ['thumbnail', 'both'])) {
                        $imageAttachment = $imageSrc;
                    }
                }

                // Publish the post
                $title = $value->content;
                if ($canBePublish == true) {
                        $articlePublished = $publisher->publish(
                            $title,
                            $contentArticle,
                            $author,
                            $imageAttachment,
                            $entityListCategory,
                            $entityPublishType
                        );

                        if (!$articlePublished) {
                            // throw new Exception('Error from post publish', 1);
                            Logger::error("Error from post publish");
                        } else {
                            $entityObject->updateHistoryPublisher($entity->id, $entity->counter);
                            // save published article
                            $published->add($messageId, $entity->id, $articlePublished);
                        }
                }
            }
        } catch (Exception $e) {
            Logger::alert("Error exeption", $e->getMessage());
        }
        return true;
    }

    public function loadTemplate($params)
    {
        $this->loadAssets();

        $this->controller->$params();
    }

    public function entityManager()
    {
        $entityCollection = new EntityCollection();
        $entityOption = new ConfigOption();
        $config = $entityOption->getConfig();

        // check http or https
        if (isset($_SERVER['HTTPS'])) {
            $linkToList = 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?page=sociallymap-rss-list';
        } else {
            $linkToList = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?page=sociallymap-rss-list';
        }

        // ACTION ENTITY : delete
        if (array_key_exists('sociallymap_deleteRSS', $_POST) && $_POST['sociallymap_deleteRSS']) {
            $idRemoving = $_POST['submit'];
            $entityCollection->deleteRowsByID($idRemoving);
        }

        // ACTION ENTITY : update
        if (array_key_exists('sociallymap_updateRSS', $_POST) && $_POST['sociallymap_updateRSS']) {
            if (isset($_POST['sociallymap_name']) && empty($_POST['sociallymap_name'])) {
                $isValid = false;
            }
            if (isset($_POST['sociallymap_entityId']) && empty($_POST['sociallymap_entityId'])) {
                $isValid = false;
            }

            if (!isset($_POST['sociallymap_activate'])) {
                $_POST['sociallymap_activate'] = 0;
            }
            if (!isset($_POST['sociallymap_category'])) {
                $_POST['sociallymap_category'] = [];
            }
            if (!isset($_POST['sociallymap_display_type'])) {
                $_POST['sociallymap_display_type'] = "tab";
            }
            if (!isset($_POST['sociallymap_link_canonical'])) {
                $_POST['sociallymap_link_canonical'] = 0;
            }
            if (!isset($_POST['sociallymap_noIndex'])) {
                $_POST['sociallymap_noIndex'] = 0;
            }
            if (!isset($_POST['sociallymap_noFollow'])) {
                $_POST['sociallymap_noFollow'] = 0;
            }
            if (!isset($_POST['sociallymap_readmore'])) {
                $_POST['sociallymap_readmore'] = "";
            } else {
                $_POST['sociallymap_readmore'] = stripslashes($_POST['sociallymap_readmore']);
            }

            $data = [
                'name'           => $_POST['sociallymap_label'],
                'category'       => $_POST['sociallymap_category'],
                'activate'       => $_POST['sociallymap_activate'],
                'sm_entity_id'   => $_POST['sociallymap_entityId'],
                'display_type'   => $_POST['sociallymap_display_type'],
                'publish_type'   => $_POST['sociallymap_publish_type'],
                'link_canonical' => $_POST['sociallymap_link_canonical'],
                'noIndex'        => $_POST['sociallymap_noIndex'],
                'noFollow'       => $_POST['sociallymap_noFollow'],
                'image'          => $_POST['sociallymap_image'],
                'readmore'       => $_POST['sociallymap_readmore'],
                'id'             => $_GET['id'],
            ];

            $entityCollection->update($data);
            wp_redirect($linkToList, 301);
            exit;
        }


        // ACTION ENTITY : post
        if (array_key_exists('sociallymap_postRSS', $_POST) && $_POST['sociallymap_postRSS']) {
            $isValid = true;

            if (isset($_POST['sociallymap_name']) && empty($_POST['sociallymap_name'])) {
                $isValid = false;
            }
            if (isset($_POST['sociallymap_entityId']) && empty($_POST['sociallymap_entityId'])) {
                $isValid = false;
            }

            if (!isset($_POST['sociallymap_activate'])) {
                $_POST['sociallymap_activate'] = 0;
            }

            if (!isset($_POST['sociallymap_category'])) {
                $_POST['sociallymap_category'] = '';
            }
            if (!isset($_POST['sociallymap_display_type'])) {
                $_POST['sociallymap_display_type'] = "tab";
            }
            if (!isset($_POST['sociallymap_link_canonical'])) {
                $_POST['sociallymap_link_canonical'] = 0;
            }
            if (!isset($_POST['sociallymap_noIndex'])) {
                $_POST['sociallymap_noIndex'] = 0;
            }
            if (!isset($_POST['sociallymap_noFollow'])) {
                $_POST['sociallymap_noFollow'] = 0;
            }
            if (!isset($_POST['sociallymap_readmore'])) {
                $_POST['sociallymap_readmore'] = "";
            }

            if ($isValid == false) {
                $_POST['sociallymap_isNotValid'] = true;
                return false;
            }

            $data = [
                'name'           => $_POST['sociallymap_name'],
                'category'       => $_POST['sociallymap_category'],
                'activate'       => $_POST['sociallymap_activate'],
                'sm_entity_id'   => $_POST['sociallymap_entityId'],
                'publish_type'   => $_POST['sociallymap_publish_type'],
                'display_type'   => $_POST['sociallymap_display_type'],
                'link_canonical' => $_POST['sociallymap_link_canonical'],
                'noIndex'        => $_POST['sociallymap_noIndex'],
                'noFollow'        => $_POST['sociallymap_noFollow'],
                'readmore'       => $_POST['sociallymap_readmore'],
                'image'          => $_POST['sociallymap_image'],
            ];

            $entityCollection->add($data);
            wp_redirect($linkToList, 301);
            exit;
        }

        // ACTION CONFIG : update
        if (array_key_exists('sociallymap_updateConfig', $_POST) && $_POST['sociallymap_updateConfig']) {
            $data = [
                1 => $_POST['sociallymap_category'],
                2 => $_POST['sociallymap_display_type'],
                3 => $_POST['sociallymap_publish_type'],
            ];

            $currentConfig = new ConfigOption;
            $currentConfig->save($data);
        }
    }

    public function loadAssets($isFront = false)
    {
        // MODAL DISPLAY TYPE IS ON
        if ($isFront) {
            wp_enqueue_style('readmore', plugin_dir_url(__FILE__).'assets/css/custom-readmore.css');
            wp_enqueue_style('fancybox', plugin_dir_url(__FILE__).'assets/css/fancybox.css');

            wp_enqueue_script('jquery');
            wp_enqueue_script('fancy', plugin_dir_url(__FILE__).'assets/js/fancybox.js');
            wp_enqueue_script('modal-manager', plugin_dir_url(__FILE__).'assets/js/modal-manager.js');
        } else {
            wp_enqueue_style('back', plugin_dir_url(__FILE__).'assets/css/back.css');
        }
    }

    public function githubConfiguration()
    {
        $config = [
            // this is the slug of your plugin
            'slug'               => plugin_basename(__FILE__),
            // this is the name of the folder your plugin lives in
            'proper_folder_name' => 'wordpress-sociallymap',
            // the GitHub API url of your GitHub repo
            'api_url'            => 'https://api.github.com/repos/pgrmCreate/wordpress-sociallymap',
            // the GitHub raw url of your GitHub repo
            'raw_url'            => 'https://raw.github.com/pgrmCreate/wordpress-sociallymap/github',
            // the GitHub url of your GitHub repo
            'github_url'         => 'https://github.com/pgrmCreate/wordpress-sociallymap',
            // the zip url of the GitHub repo
            'zip_url'            => 'https://github.com/pgrmCreate/wordpress-sociallymap/zipball/github',
            // whether WP should check the validity of the SSL cert when getting an update,
            // see https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/2 and
            // https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/4 for details
            'sslverify'          => true,
            // which version of WordPress does your plugin require?
            'requires'           => '4.3.1',
            // which version of WordPress is your plugin tested up to?
            'tested'             => '4.3.1',
            // which file to use as the readme for the version number
            'readme'             => 'README.md',
            // Access private repositories by authorizing under Appearance > GitHub Updates
            // when this example plugin is installed
            'access_token'       => '',
        ];

        new githubUpdater($config);
    }
}

register_activation_hook(__FILE__, ['SociallymapPlugin', 'install']);

new SociallymapPlugin();


