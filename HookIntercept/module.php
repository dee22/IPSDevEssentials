<?php

declare(strict_types=1);

class HookIntercept extends IPSModule
{
	public function Create()
	{
		parent::Create();
		$this->RegisterIntercepts();
	}

	public function Destroy()
	{
		$this->UnregisterIntercepts();
		parent::Destroy();
	}

	public function ApplyChanges()
	{
		parent::ApplyChanges();
	}


	public function GetConfigurationForm()
	{
		//$this->SetBuffer('TargetDirection', '');
		//$json = json_decode(file_get_contents(__DIR__ . '/form.json', true), true);
		//		$json['elements'][5]['visible'] = $this->ReadPropertyBoolean('EnableTimer');
		//return json_encode($json);
	}

	/**
	 * This function will be called by the hook control.
	 * Visibility should be protected!
	 */
	protected function ProcessHookData()
	{
		$data = file_get_contents('php://input');
		$hook = $_SERVER['HOOK'];
		$forwardHook = $this->convertToForwardHook($hook);
		$this->RedirectHookData('localhost', $data, $forwardHook);
	}

	/**
	 * Redirect the data to the given hook on the defined host.
	 */
	function RedirectHookData(string $host, string $data, string $hookUrl)
	{
		$url = "http://$host:3777$hookUrl";
		$this->LogMessage('Forward-URL: ' . $url, KL_DEBUG);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$headers = array(
			"Accept: application/json",
			"Content-Type: application/json",
		);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

		$resp = curl_exec($curl);
		curl_close($curl);
		$this->LogMessage('Forward-ANSWER: ' . $resp, KL_DEBUG);
	}

	/**
	 * Replaces the original hook with a new hook that points to this instance.
	 * This Instance then calls all the registered Middleware.
	 * After that, the data will be forwarded to the original hook to proceed as normal.
	 * The route of the original hook will be changed to /original/{original route}
	 * OnDestroy of this instance, the original hook will be restored.
	 */
	public function RegisterIntercepts()
	{
		$webHook = $this->getWebHook();
		$hooks = $webHook['hooks'];
		$id = $this->InstanceID;
		$iHooks = [];
		foreach ($hooks as $index => $hook) {
			if (strpos($hook['Hook'], "original/") === false && $hook['TargetID'] !== $id) {
				$iHooks[] = $index;
			}
		}
		$newHooks = [];
		foreach ($iHooks as $index) {
			// modify existing hook
			$endpoint = $hooks[$index]['Hook'];
			$hooks[$index]['Hook'] = $this->convertToForwardHook($endpoint);
			// add new hook
			$newHooks[] = [
				'Hook' => $endpoint,
				'TargetID' => $id
			];
		}
		// append new hooks
		$hooks = array_merge($hooks, $newHooks);
		IPS_SetProperty($webHook['id'], 'Hooks', json_encode($hooks));
		IPS_ApplyChanges($webHook['id']);
	}

	/**
	 * Restores the original hooks.
	 */
	public function UnregisterIntercepts()
	{
		$webHook = $this->getWebHook();
		$hooks = $webHook['hooks'];
		foreach ($hooks as $index => $hook) {
			if (strpos($hook['Hook'], 'original/') !== false) {
				$hooks[$index]['Hook'] = str_replace('original/', '', $hook['Hook']);
				continue;
			}
			if ($hook['TargetID'] === $this->InstanceID) {
				unset($hooks[$index]);
			}
		}
		IPS_SetProperty($webHook['id'], 'Hooks', json_encode($hooks));
		IPS_ApplyChanges($webHook['id']);
	}

	/****************************
	 * Private helper functions *
	 ****************************/
	private function getWebHook()
	{
		$webHook = [
			'guid' => '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}',
			'id' => -1,
			'hooks' => []
		];
		$webHook['id'] = IPS_GetInstanceListByModuleID($webHook['guid'])[0];
		$webHook['hooks'] = json_decode(IPS_GetProperty($webHook['id'], 'Hooks'), true);
		return $webHook;
	}

	private function convertToForwardHook(string $hook)
	{
		$path = explode('/', $hook);
		$pos = (int) ($path[0] == '');
		array_splice($path, $pos + 1, 0, 'original');
		return implode('/', $path);
	}
}