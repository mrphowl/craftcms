<?php

/**
 *
 */
class CoreUpdater implements IUpdater
{
	private $_buildsToUpdate = null;
	private $_migrationsToRun = null;
	private $_blocksUpdateInfo = null;

	/**
	 */
	function __construct()
	{
		$this->_blocksUpdateInfo = Blocks::app()->update->getUpdateInfo(true);
		$this->_migrationsToRun = null;
		$this->_buildsToUpdate = $this->_blocksUpdateInfo->newerReleases;
	}

	/**
	 */
	public function checkRequirements()
	{
		$localPHPVersion = Blocks::app()->config->localPHPVersion;
		$localDatabaseType = Blocks::app()->getDbConfig('type');
		$localDatabaseVersion = Blocks::app()->db->serverVersion;
		$requiredDatabaseVersion = Blocks::app()->config->getDatabaseRequiredVersionByType($localDatabaseType);
		$requiredPHPVersion = Blocks::app()->config->requiredPHPVersion;

		$phpCompat = version_compare($localPHPVersion, $requiredPHPVersion, '>=');
		$databaseCompat = version_compare($localDatabaseVersion, $requiredDatabaseVersion, '>=');

		if (!$phpCompat && !$databaseCompat)
			throw new BlocksException('The update cannot be installed because Blocks requires PHP version '.$requiredPHPVersion.' or higher and '.$localDatabaseType.' version '.$requiredDatabaseVersion.' or higher.  You have PHP version '.$localPHPVersion.' and '.$localDatabaseType.' version '.$localDatabaseVersion.' installed.');
		else
			if (!$phpCompat)
				throw new BlocksException('The update cannot be installed because Blocks requires PHP version '.$requiredPHPVersion.' or higher and you have PHP version '.$localPHPVersion.' installed.');
			else
				if (!$databaseCompat)
					throw new BlocksException('The update cannot be installed because Blocks requires '.$localDatabaseType.' version '.$requiredDatabaseVersion.' or higher and you have '.$localDatabaseType.' version '.$localPHPVersion.' installed.');
	}

	/**
	 * @return bool
	 * @throws BlocksException
	 */
	public function start()
	{
		$this->checkRequirements();

		if ($this->_buildsToUpdate == null)
			throw new BlocksException('Blocks is already up to date.');

		foreach ($this->_buildsToUpdate as $buildToUpdate)
		{
			$downloadFilePath = Blocks::app()->path->runtimePath.UpdateHelper::constructCoreReleasePatchFileName($buildToUpdate->version, $buildToUpdate->build, Blocks::getEdition());

			// download the package
			if (!$this->downloadPackage($buildToUpdate->version, $buildToUpdate->build, $downloadFilePath))
				throw new BlocksException('There was a problem downloading the package.');

			// validate
			if (!$this->validatePackage($buildToUpdate->version, $buildToUpdate->build, $downloadFilePath))
				throw new BlocksException('There was a problem validating the downloaded package.');

			// unpack
			if (!$this->unpackPackage($downloadFilePath))
				throw new BlocksException('There was a problem unpacking the downloaded package.');
		}

		$manifest = $this->generateMasterManifest();

		if (!empty($this->_migrationsToRun))
		{
			if ($this->_migrationsToRun != null)
			{
				if (!$this->doDatabaseUpdate())
					throw new BlocksException('There was a problem updating your database.');
			}
		}

		if (!$this->backupFiles($manifest))
			throw new BlocksException('There was a problem backing up your files for the update.');

		if (!UpdateHelper::doFileUpdate($manifest))
			throw new BlocksException('There was a problem updating your files.');

		$this->cleanTempFiles($manifest);
		return true;
	}

	/**
	 * @return
	 */
	public function generateMasterManifest()
	{
		$masterManifest = Blocks::app()->file->set(Blocks::app()->path->runtimePath.'manifest_'.uniqid());
		$masterManifest->exists ? $masterManifest->delete() : $masterManifest->create();

		$updatedFiles = array();

		foreach ($this->_buildsToUpdate as $buildToUpdate)
		{
			$downloadedFile = Blocks::app()->path->runtimePath.UpdateHelper::constructCoreReleasePatchFileName($buildToUpdate->version, $buildToUpdate->build, Blocks::getEdition());
			$tempDir = UpdateHelper::getTempDirForPackage($downloadedFile);

			$manifestData = UpdateHelper::getManifestData($tempDir->realPath);

			for ($i = 0; $i < count($manifestData); $i++)
			{
				// first line is version information
				if ($i == 0)
					continue;

				// normalize directory separators
				$manifestData[$i] = Blocks::app()->path->normalizeDirectorySeparators($manifestData[$i]);
				$row = explode(';', $manifestData[$i]);

				// catch any rogue blank lines
				if (count($row) > 1)
				{
					$counter = 0;
					$found = UpdateHelper::inManifestList($counter, $manifestData[$i], $updatedFiles);

					if ($found)
						$updatedFiles[$counter] = $tempDir->realPath.';'.$manifestData[$i];
					else
						$updatedFiles[] = $tempDir->realPath.';'.$manifestData[$i];
				}
			}
		}

		if (count($updatedFiles) > 0)
		{
			// write the updated files out
			$uniqueUpdatedFiles = array_unique($updatedFiles, SORT_STRING);

			for ($counter = 0; $counter < count($uniqueUpdatedFiles); $counter++)
			{
				$row = explode(';', $uniqueUpdatedFiles[$counter]);

				// we found a migration
				if (strpos($row[1], '/migrations/') !== false && $row[2] == PatchManifestFileAction::Add)
					$this->_migrationsToRun[] = UpdateHelper::copyMigrationFile($row[0].'/'.$row[1]);

				$manifestContent = $uniqueUpdatedFiles[$counter].PHP_EOL;

				// if we're on the last one don't write the last newline.
				if ($counter == count($uniqueUpdatedFiles) - 1)
					$manifestContent = $uniqueUpdatedFiles[$counter];

				$masterManifest->setContents(null, $manifestContent, true, FILE_APPEND);
			}
		}

		return $masterManifest;
	}

	/**
	 * @todo Fix
	 * @return bool
	 */
	public function putSiteInMaintenanceMode()
	{
		$file = Blocks::app()->file->set(Blocks::app()->path->basePath.'../index.php', false);
		$contents = $file->contents;
		$contents = str_replace('//header(\'location:offline.php\');', 'header(\'location:offline.php\');', $contents);
		$file->setContents(null, $contents);
		return true;
	}

	/**
	 * @return bool
	 */
	public function doDatabaseUpdate()
	{
		foreach ($this->_migrationsToRun as $migrationName)
		{
			$response = Migration::run($migrationName);
			if (strpos($response, 'Migrated up successfully.') !== false || strpos($response, 'No new migration found.') !== false)
				return false;
		}

		return true;
	}

	/**
	 * @param $manifestFile
	 */
	public function cleanTempFiles($manifestFile)
	{
		$manifestData = explode("\n", $manifestFile->contents);

		foreach ($manifestData as $row)
		{
			$rowData = explode(';', $row);
			$tempDir = Blocks::app()->file->set($rowData[0]);
			$tempFile = Blocks::app()->file->set(str_replace('_temp', '', $rowData[0]).'.zip');

			// delete the temp dirs
			if ($tempDir->exists)
				$tempDir->delete();

			// delete the downloaded zip file
			if ($tempFile->exists)
				$tempFile->delete();

			// delete the cms files we backed up.
			$backupFile = Blocks::app()->file->set(Blocks::app()->path->basePath.'../'.$rowData[1].'.bak');
			if ($backupFile->exists)
				$backupFile->delete();
		}

		// delete the manifest file.
		$manifestFile->delete();
	}

	/**
	 * @param $version
	 * @param $build
	 * @param $destinationPath
	 * @return bool
	 */
	public function downloadPackage($version, $build, $destinationPath)
	{
		$params = array(
			'versionNumber' => $version,
			'buildNumber' => $build,
			'type' => CoreReleaseFileType::Patch
		);

		$et = new ET(ETEndPoints::DownloadPackage(), 60);
		$et->setStreamPath($destinationPath);
		$et->getPackage()->data = $params;
		if ($et->phoneHome())
			return true;

		return false;
	}

	/**
	 * @param $version
	 * @param $build
	 * @param $destinationPath
	 * @return bool
	 * @throws BlocksException
	 */
	public function validatePackage($version, $build, $destinationPath)
	{
		$params = array(
			'versionNumber' => $version,
			'buildNumber' => $build,
			'type' => CoreReleaseFileType::Patch
		);

		$et = new ET(ETEndPoints::GetCoreReleaseFileMD5());
		$et->getPackage()->data = $params;
		$package = $et->phoneHome();

		$sourceMD5 = $package->data;

		if(StringHelper::IsNullOrEmpty($sourceMD5))
			throw new BlocksException('Error in getting the MD5 hash for the download.');

		$localFile = Blocks::app()->file->set($destinationPath, false);
		$localMD5 = $localFile->generateMD5();

		if($localMD5 === $sourceMD5)
			return true;

		return false;
	}

	/**
	 * @param $downloadPath
	 * @return bool
	 */
	public function unpackPackage($downloadPath)
	{
		$tempDir = UpdateHelper::getTempDirForPackage($downloadPath);
		$tempDir->exists ? $tempDir->delete() : $tempDir->createDir(0754);

		$downloadPath = Blocks::app()->file->set($downloadPath);
		if ($downloadPath->unzip($tempDir->realPath))
			return true;

		return false;
	}

	/**
	 * @param $masterManifest
	 * @return bool
	 */
	public function backupFiles($masterManifest)
	{
		$manifestData = explode("\r\n", $masterManifest->contents);

		try
		{
			foreach ($manifestData as $row)
			{
				$rowData = explode(';', $row);
				$file = Blocks::app()->file->set(Blocks::app()->path->basePath.'../'.$rowData[1]);

				// if the file doesn't exist, it's a new file
				if ($file->exists)
					$file->copy($file->realPath.'.bak');
			}
		}
		catch (Exception $e)
		{
			Blocks::log('Error updating files: '.$e->getMessage());
			UpdateHelper::rollBackFileChanges($masterManifest);
			return false;
		}

		return true;
	}
}
