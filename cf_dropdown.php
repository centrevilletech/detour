<?php
if (! defined('ABSPATH')) exit; // Exit if accessed directly
if (!class_exists('p404redirect_dropdown')) {
	class p404redirect_dropdown
	{

		private $name = 'drop';
		private $options = array();
		private $class = '';
		private $onchange = '';

		public function __construct($str, $class = '', $onchange = '')
		{
			$this->name = $str;

			if ($class != '')
				$this->class = $class;

			if ($onchange != '')
				$this->onchange = $onchange;
		}

		public function add($name, $value)
		{
			$this->options[] = array(
				'key' => esc_html($value),
				'name' => esc_html($name)
			);
		}

		public function dropdown_print()
		{
?>
			<select size='1' name='<?php esc_attr_e($this->name); ?>' <?php if ($this->onchange != '') { ?> onchange='<?php esc_attr_e($this->onchange); ?>' <?php } ?> id='<?php esc_attr_e($this->name); ?>'>
				<?php
				foreach ($this->options as $options) {
				?>
					<option value="<?php esc_html_e($options['key']); ?>"><?php esc_html_e($options['name']); ?></option>
				<?php
				}
				?>
			</select>
		<?php

		}

		public function select($str)
		{
		?>
			<script>
				document.getElementById('<?php echo esc_js($this->name); ?>').value = '<?php echo esc_js($str); ?>'
			</script>
<?php
		}

		public function data_bind($tbl, $name = "name", $id = "id", $where = "", $order = "", $limit = "")
		{
			global $mysql;
			$res = $mysql->sql(" select $id,$name from PREFIX_$tbl $where $order $limit ");
			while ($ar = mysqli_fetch_array($res)) {
				$this->add($ar[1], $ar[0]);
			}
		}
	}
}
