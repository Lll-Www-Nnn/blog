<?php

/*
 * CKFinder
 * ========
 * https://ckeditor.com/ckfinder/
 * Copyright (c) 2007-2021, CKSource - Frederico Knabben. All rights reserved.
 *
 * The software, this file and its contents are subject to the CKFinder
 * License. Please read the license.txt file before using, installing, copying,
 * modifying or distribute this file or part of its contents. The contents of
 * this file is part of the Source Code of CKFinder.
 */

namespace CKSource\CKFinder\Command;

use CKSource\CKFinder\Acl\Permission;
use CKSource\CKFinder\Cache\CacheManager;
use CKSource\CKFinder\Config;
use CKSource\CKFinder\Error;
use CKSource\CKFinder\Event\AfterCommandEvent;
use CKSource\CKFinder\Event\CKFinderEvent;
use CKSource\CKFinder\Event\FileUploadEvent;
use CKSource\CKFinder\Exception\InvalidExtensionException;
use CKSource\CKFinder\Exception\InvalidNameException;
use CKSource\CKFinder\Exception\InvalidUploadException;
use CKSource\CKFinder\Filesystem\File\UploadedFile;
use CKSource\CKFinder\Filesystem\Folder\WorkingFolder;
use CKSource\CKFinder\Filesystem\Path;
use CKSource\CKFinder\Image;
use CKSource\CKFinder\Thumbnail\ThumbnailRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

class FileUpload extends CommandAbstract
{
    protected $requestMethod = Request::METHOD_POST;

    protected $requires = [Permission::FILE_CREATE];

    public function execute(Request $request, WorkingFolder $workingFolder, EventDispatcher $dispatcher, Config $config, CacheManager $cache, ThumbnailRepository $thumbsRepository)
    {
        // #111 IE9 download JSON issue workaround
        if ($request->get('asPlainText')) {
            $uploadEvents = [
                CKFinderEvent::AFTER_COMMAND_FILE_UPLOAD,
                CKFinderEvent::AFTER_COMMAND_QUICK_UPLOAD,
            ];

            foreach ($uploadEvents as $eventName) {
                $dispatcher->addListener($eventName, function (AfterCommandEvent $event) {
                    $response = $event->getResponse();
                    $response->headers->set('Content-Type', 'text/plain');
                });
            }
        }

        $uploaded = 0;

        $warningErrorCode = null;
        $upload = $request->files->get('upload');

        if (null === $upload) {
            throw new InvalidUploadException();
        }

        $uploadedFile = new UploadedFile($upload, $this->app);

        if (!$uploadedFile->isValid()) {
            throw new InvalidUploadException($uploadedFile->getErrorMessage());
        }

        $uploadedFile->sanitizeFilename();

        if ($uploadedFile->wasRenamed()) {
            $warningErrorCode = Error::UPLOADED_INVALID_NAME_RENAMED;
        }

        if (!$uploadedFile->hasValidFilename() || $uploadedFile->isHiddenFile()) {
            throw new InvalidNameException();
        }

        if (!$uploadedFile->hasAllowedExtension()) {
            throw new InvalidExtensionException();
        }

        // Autorename if required
        $overwriteOnUpload = $config->get('overwriteOnUpload');
        if (!$overwriteOnUpload && $uploadedFile->autorename()) {
            $warningErrorCode = Error::UPLOADED_FILE_RENAMED;
        }

        //$fileName = $uploadedFile->getFilename();
        $fileName=$this->randName().'.'.$uploadedFile->getExtension();

        if (!$uploadedFile->isAllowedHtmlFile() && $uploadedFile->containsHtml()) {
            throw new InvalidUploadException('HTML detected in disallowed file type', Error::UPLOADED_WRONG_HTML_FILE);
        }

        if ($config->get('secureImageUploads') && $uploadedFile->isImage() && !$uploadedFile->isValidImage()) {
            throw new InvalidUploadException('Invalid upload: corrupted image', Error::UPLOADED_CORRUPT);
        }

        $maxFileSize = $workingFolder->getResourceType()->getMaxSize();

        if (!$config->get('checkSizeAfterScaling') && $maxFileSize && $uploadedFile->getSize() > $maxFileSize) {
            throw new InvalidUploadException('Uploaded file is too big', Error::UPLOADED_TOO_BIG);
        }

        if (Image::isSupportedExtension($uploadedFile->getExtension())) {
            $imagesConfig = $config->get('images');
            $image = Image::create($uploadedFile->getContents());

            if ($imagesConfig['maxWidth'] && $image->getWidth() > $imagesConfig['maxWidth'] ||
                $imagesConfig['maxHeight'] && $image->getHeight() > $imagesConfig['maxHeight']) {
                $image->resize($imagesConfig['maxWidth'], $imagesConfig['maxHeight'], $imagesConfig['quality']);
                $imageData = $image->getData();
                $uploadedFile->save($imageData);
            }

            $cache->set(
                Path::combine(
                    $workingFolder->getResourceType()->getName(),
                    $workingFolder->getClientCurrentFolder(),
                    $fileName
                ),
                $image->getInfo()
            );

            unset($imageData, $image);
        }

        if ($maxFileSize && $uploadedFile->getSize() > $maxFileSize) {
            throw new InvalidUploadException('Uploaded file is too big', Error::UPLOADED_TOO_BIG);
        }

        $event = new FileUploadEvent($this->app, $uploadedFile);
        $dispatcher->dispatch($event, CKFinderEvent::FILE_UPLOAD);

        if (!$event->isPropagationStopped()) {
            $uploadedFileStream = $uploadedFile->getContentsStream();
            $uploaded = (int) $workingFolder->putStream($fileName, $uploadedFileStream, $uploadedFile->getMimeType());

            if (\is_resource($uploadedFileStream)) {
                fclose($uploadedFileStream);
            }

            if ($overwriteOnUpload) {
                $thumbsRepository->deleteThumbnails(
                    $workingFolder->getResourceType(),
                    $workingFolder->getClientCurrentFolder(),
                    $fileName
                );
            }

            if (!$uploaded) {
                $warningErrorCode = Error::ACCESS_DENIED;
            }
        }

        $responseData = [
            'fileName' => $fileName,
            'uploaded' => $uploaded,
        ];

        if ($warningErrorCode) {
            $errorMessage = $this->app['translator']->translateErrorMessage($warningErrorCode, ['name' => $fileName]);
            $responseData['error'] = [
                'number' => $warningErrorCode,
                'message' => $errorMessage,
            ];
        }

        return $responseData;
    }
    //生成一个随机名字的方法
    private function randName()
    {
        //1.生成文件的时间部分
        $newname=date('YmdHis');
        //2.加上随机产生的6位数
        $str="0987654321";
        for($i=0;$i<6;$i++)
        {
            $newname.=$str[mt_rand(0,strlen($str)-1)];
        }
        return $newname;
    }
}
