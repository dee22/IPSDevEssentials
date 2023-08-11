<?php

declare(strict_types=1);

class HookIntercept extends IPSModule
{
	public function Create()
	{
		parent::Create();
		$this->mountHooks();
		$this->RegisterPropertyString('Forwarding', '[]');
		$this->RegisterPropertyString('Middleware', '[]');
	}

	public function Destroy()
	{
		$this->unmountHooks();
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
		// get input data
		$data = file_get_contents('php://input');

		// inject custom defined middleware

		// forward data to original hook
		$hook = $_SERVER['HOOK'];
		$forwardHook = $this->convertToForwardHook($hook);
		$this->RedirectHookData('localhost', $data, $forwardHook);
	}

	/**
	 * Redirect the data to the given hook on the defined host.
	 */
	function RedirectHookData(string $data, string $hookUrl, string $host = 'localhost')
	{
		$url = "http://$host:3777$hookUrl";
		$this->LogMessage('Forward-URL: ' . $url, KL_DEBUG);

		$headers = [
			"Accept: application/json",
			"Content-Type: application/json"
		];
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		$resp = curl_exec($curl);
		curl_close($curl);
		$this->LogMessage('Forward-ANSWER: ' . $resp, KL_DEBUG);
		return $resp;
	}

	/**
	 * Replaces the original hook with a new hook which points to this module-instance.
	 * When receiving a web-request, this module calls all the registered middlewares.
	 * After that, the data will be forwarded to the original hook to proceed as normal.
	 * The route of the original hook will be changed to {original_route}/proceed.
	 * OnDestroy of this instance, the original hooks will be restored.
	 */
	private function mountHooks($hookSuffix = '/proceed')
	{
		$hooks = $this->getWebHooks();
		$injectID = $this->InstanceID;

		$untrackedHooks = array_filter($hooks, function ($h) use ($injectID, $hookSuffix) {
			return (strpos($h['Hook'], $hookSuffix) === false &&
				$h['TargetID'] !== $injectID);
		});
		$trackedHooks = array_filter($hooks, function ($h) use ($injectID, $hookSuffix) {
			return (strpos($h['Hook'], $hookSuffix) !== false ||
				$h['TargetID'] === $injectID);
		});

		$injectHooks = array_map(function ($hook) use ($injectID) {
			$hook['TargetID'] = $injectID;
			return $hook;
		}, $untrackedHooks); // copy of untracked hooks to listen to.

		$proceedHooks = array_map(function ($hook) use ($hookSuffix) {
			$hook['Hook'] = str_replace('//', '/', $hook['Hook'] . $hookSuffix);
			return $hook;
		}, $untrackedHooks); // modified original hooks to proceed to.

		$allHooks = array_merge($trackedHooks, $injectHooks, $proceedHooks);
		return $this->setWebHooks($allHooks);
	}

	/**
	 * Restore the original and remove the intercept hooks.
	 */
	function unmountHooks($hookSuffix = '/proceed')
	{
		$hooks = $this->getWebHooks();
		$injectID = $this->InstanceID;

		$untrackedHooks = array_filter($hooks, function ($h) use ($hookSuffix, $injectID) {
			return strpos($h['Hook'], $hookSuffix) === false && $h['TargetID'] !== $injectID;
		});
		$reversedHooks = array_filter($hooks, function ($h) use ($hookSuffix) {
			return strpos($h['Hook'], $hookSuffix);
		});
		$reversedHooks = array_map(function ($h) use ($hookSuffix) {
			$h['Hook'] = str_replace($hookSuffix, '', $h['Hook']);
			return $h;
		}, $reversedHooks);

		$hooks = array_merge($reversedHooks, $untrackedHooks);
		return $this->setWebHooks($hooks);
	}

	/****************************
	 * Private helper functions *
	 ****************************/

	private function getWebHooks()
	{
		$GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
		$id = IPS_GetInstanceListByModuleID($GUID)[0];
		return json_decode(IPS_GetProperty($id, 'Hooks'), true);
	}

	private function setWebHooks($hooks)
	{
		$GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
		$id = IPS_GetInstanceListByModuleID($GUID)[0];
		IPS_SetProperty($id, 'Hooks', json_encode($hooks));
		IPS_ApplyChanges($id);
	}

	private function convertToForwardHook(string $hook)
	{
		$path = explode('/', $hook);
		$pos = (int) ($path[0] == '');
		array_splice($path, $pos + 1, 0, 'original');
		return implode('/', $path);
	}
}