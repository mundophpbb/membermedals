<?php

namespace mundophpbb\membermedalsattachments;

class ext extends \phpbb\extension\base
{
	protected $required_extension_path = 'ext/mundophpbb/membermedals/';
	protected $required_interface_file = 'contract/rule_provider_interface.php';
	protected $required_interface_name = 'mundophpbb\\membermedals\\contract\\rule_provider_interface';

	/**
	 * Ajuste aqui para a versão mínima real do pacote principal.
	 * Exemplo: 1.0.1, 1.1.0, 2.0.0...
	 */
	protected $minimum_membermedals_version = '1.0.0';

	public function is_enableable()
	{
		$root_path = $this->container->getParameter('core.root_path');
		$main_ext_path = $root_path . $this->required_extension_path;
		$interface_path = $main_ext_path . $this->required_interface_file;

		// 1) A extensão principal precisa existir
		if (!is_dir($main_ext_path))
		{
			return false;
		}

		// 2) O arquivo da interface precisa existir
		if (!is_file($interface_path))
		{
			return false;
		}

		// 3) Tenta validar a interface exigida
		require_once $interface_path;

		if (!interface_exists($this->required_interface_name))
		{
			return false;
		}

		// 4) Se houver version no composer.json da principal, valida a versão mínima
		$composer_path = $main_ext_path . 'composer.json';

		if (is_file($composer_path))
		{
			$composer_data = json_decode(file_get_contents($composer_path), true);

			if (is_array($composer_data) && !empty($composer_data['version']))
			{
				if (version_compare($composer_data['version'], $this->minimum_membermedals_version, '<'))
				{
					return false;
				}
			}
		}

		return parent::is_enableable();
	}
}