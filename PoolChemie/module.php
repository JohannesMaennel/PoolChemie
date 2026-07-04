<?php

declare(strict_types=1);
	class PoolChemie extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			
			$this->ConnectParent("{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}");

		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();

			
			$this->SetReceiveDataFilter('.*Pool/Chemiewaage.*');
			IPS_LogMessage('PoolChemie', 'ApplyChanges');
		}

		public function ReceiveData($JSONString)
		{
			IPS_LogMessage('PoolChemie',$JSONString);
		}
	}
