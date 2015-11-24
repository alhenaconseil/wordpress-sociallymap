<?php
	class Templater {
		private $urlBase;

		public function __construct () {
			$this->urlBase =  dirname( __FILE__ ) . '/../views/';
		}

		public function loadAdminPage ($page, $data = null) {
			load_plugin_textdomain('sociallymap', false, "../".basename(dirname( __FILE__ )) );

			set_query_var('data', ['data' => $data]);

			ob_start();
				load_template($this->urlBase.'menu.php');
				load_template($this->urlBase.$page);
				$view = ob_get_contents();
			ob_end_clean();

			return $view;
		}

		public function loadReadMore() {
			load_plugin_textdomain('sociallymap', false, "../".basename(dirname( __FILE__ )) );

			ob_start();
				load_template($this->urlBase.'readmore-template.php', true);
				$readmore = ob_get_contents();
			ob_end_clean();

			return $readmore;
		}

		public function formatReadMoreUrl ($content, $url) {
			$contentFormated = str_replace('href=""', 'href="'.$url.'"', $content);

			return $contentFormated;
		}
}