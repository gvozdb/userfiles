<?php

class modUserFileUploadProcessor extends modObjectCreateProcessor
{
    public $classKey = 'UserFile';
    public $objectType = 'UserFile';
    public $primaryKeyField = 'id';
    public $languageTopics = array('userfiles');
    public $permission = 'userfiles_file_upload';

    /** @var UserFile $object */
    public $object;
    /** @var UserFiles $UserFiles */
    public $UserFiles;
    /** @var Tools $Tools */
    public $Tools;

    /** @var modMediaSource $mediaSource */
    public $mediaSource;
    /** @var array $mediaSourceProperties */
    public $mediaSourceProperties;
    /** @var null $data */
    protected $data = null;

    public function initialize()
    {
        if (!$this->modx->hasPermission($this->permission)) {
            return $this->modx->lexicon('userfiles_err_permission_denied');
        }

        $primaryKey = $this->getProperty($this->primaryKeyField, false);
        if ($this->getProperty('crop', false)) {
            if (!$this->object = $this->modx->getObject($this->classKey, $primaryKey)) {
                return $this->modx->lexicon($this->objectType . '_err_nfs',
                    array($this->primaryKeyField => $primaryKey));
            }
        } else {
            $this->object = $this->modx->newObject($this->classKey);
        }

        $this->UserFiles = $this->modx->getService('userfiles');
        $this->UserFiles->initialize();
        $this->Tools = $this->UserFiles->Tools;

        $checkSource = $this->checkSource();
        if ($checkSource !== true) {
            return $this->UserFiles->lexicon('err_source_initialize');
        }

        $checkFile = $this->checkFile();
        if ($checkFile !== true) {
            return $checkFile;
        }

        return true;
    }

    /** {@inheritDoc} */
    protected function checkSource()
    {
        $source = $this->getProperty('source', $this->object->get('source'));
        $this->object->set('source', $source);

        if ($initialized = $this->object->initialized()) {
            $this->mediaSource = $this->object->mediaSource;
            $this->mediaSourceProperties = $this->object->mediaSourceProperties;
        }

        return $initialized;
    }

    protected function checkFile()
    {
        if (empty($_FILES['file'])) {
            return $this->UserFiles->lexicon('err_file_ns');
        }
        if (!file_exists($_FILES['file']['tmp_name']) OR !is_uploaded_file($_FILES['file']['tmp_name'])) {
            return $this->UserFiles->lexicon('err_file_ns');
        }
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return $this->UserFiles->lexicon('err_file_ns');
        }

        $tnm = $_FILES['file']['tmp_name'];
        $name = $_FILES['file']['name'];

        $size = @filesize($tnm);
        $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tnm);

        $tim = getimagesize($tnm);
        $width = $height = 0;
        if (is_array($tim)) {
            $width = $tim[0];
            $height = $tim[1];
        }

        $type = explode('.', $name);
        $type = end($type);
        $name = rtrim(str_replace($type, '', $name), '.');
        $hash = hash_file('sha1', $tnm);

        $this->data = array(
            'tmp_name'   => $tnm,
            'size'       => $size,
            'mime'       => $mime,
            'type'       => $type,
            'name'       => $name,
            'width'      => $width,
            'height'     => $height,
            'hash'       => $hash,
            'properties' => $this->modx->toJSON(array(
                'w' => $width,
                'h' => $height,
                'f' => $type
            ))
        );

        return true;

    }

    /** {@inheritDoc} */
    public function beforeSet()
    {
        if (empty($this->data)) {
            return $this->UserFiles->lexicon('err_file_ns');
        }

        foreach (array('tmp_name', 'size', 'mime', 'type', 'name', 'width', 'height', 'hash', 'properties') as $key) {
            $this->setProperty($key, strtolower($this->data[$key]));
        }

        $maxUploadSize = $this->modx->getOption('maxUploadSize', $this->mediaSourceProperties, 0, true);
        if ($this->getProperty('size') > $maxUploadSize) {
            return $this->UserFiles->lexicon('err_file_size');
        }

        $allowedFileTypes = $this->modx->getOption('allowedFileTypes', $this->mediaSourceProperties, '', true);
        $allowedFileTypes = $this->UserFiles->Tools->explodeAndClean($allowedFileTypes);
        if (!in_array($this->getProperty('type'), $allowedFileTypes)) {
            return $this->UserFiles->lexicon('err_file_type');
        }

        $imageNameType = $this->modx->getOption('imageNameType', $this->mediaSourceProperties, 'hash', true);
        switch ($imageNameType) {
            case 'friendly':
                $name = $this->getProperty('name');
                /** @var  modResource $resource */
                $resource = $this->modx->newObject('modResource');
                $name = $resource->cleanAlias($name);
                break;
            case 'hash':
            default:
                $name = $this->getProperty('hash');
                break;
        }

        $this->setProperty('parent', $this->getProperty('parent', 0));
        $this->setProperty('class', $this->getProperty('class', 'modResource'));
        $this->setProperty('list', $this->getProperty('list', 'default'));
        $this->setProperty('context', $this->getProperty('context', 'web'));

        $path = array();
        $path[] = $this->getProperty('list');
        $path[] = $this->getProperty('class');
        $path[] = $this->getProperty('parent');
        $path[] = null;
        $path = strtolower(implode('/', $path));
        $this->setProperty('path', $path);

        $pls = array(
            'pl' => array(
                '{name}',
                '{id}',
                '{class}',
                '{list}',
                '{session}',
                '{createdby}',
                '{source}',
                '{context}',
                '{w}',
                '{h}',
                '{q}',
                '{zc}',
                '{bg}',
                '{ext}',
                '{rand}'
            ),
            'vl' => array(
                $name,
                0,
                $this->getProperty('class'),
                $this->getProperty('list'),
                session_id(),
                $this->modx->user->id,
                $this->getProperty('source'),
                $this->getProperty('context'),
                '',
                '',
                '',
                '',
                '',
                $this->getProperty('type'),
                strtolower(strtr(base64_encode(openssl_random_pseudo_bytes(2)), "+/=", "zzz"))
            )
        );

        $filename = $this->object->getFileName();
        $filename = strtolower(str_replace($pls['pl'], $pls['vl'], $filename));

        $this->setProperty('file', $filename);

        return parent::beforeSet();
    }


    /** {@inheritDoc} */
    public function beforeSave()
    {
        if (empty($this->data)) {
            return $this->UserFiles->lexicon('err_file_ns');
        }

        $dsFields = $this->UserFiles->getOption('duplicate_search_fields', null, 'parent,class,list,hash,source', true);
        $dsFields = $this->UserFiles->explodeAndClean($dsFields);

        $q = $this->modx->newQuery($this->classKey);
        foreach ($dsFields as $k) {
            $q->where(array($k => $this->object->get($k)));
        }

        if (!empty($this->modx->user->id)) {
            $q->where(array(
                'createdby' => $this->modx->user->id,
            ));
        } else {
            $q->where(array(
                'session' => session_id(),
            ));
        }

        if ($this->modx->getCount($this->classKey, $q)) {
            return $this->UserFiles->lexicon('err_file_exists', array('file' => $this->data['name']));
        }

        $path = '';
        foreach (explode('/', rtrim($this->object->get('path'), '/')) as $dir) {
            $path .= $dir . '/';
            $this->mediaSource->createContainer($path, '/');
        }
        $this->mediaSource->createContainer($this->object->get('path'), '/');
        $this->mediaSource->errors = array();
        if ($this->mediaSource instanceof modFileMediaSource) {
            $file = $this->mediaSource->createObject(
                $this->object->get('path'),
                $this->object->get('file'),
                ''
            );
            if ($file) {
                copy($this->data['tmp_name'], urldecode($file));
            }
        } else {


            $file = $this->mediaSource->uploadObjectsToContainer(
                $this->object->get('path'),
                array(
                    array(
                        'name'     => $this->object->get('file'),
                        'tmp_name' => $this->data['tmp_name']
                    )
                )
            );
        }
        unlink($this->data['tmp_name']);

        if ($file) {
            $url = $this->mediaSource->getObjectUrl($this->object->get('path') . $this->object->get('file'));
            $this->object->set('url', $url);
        } else {
            return $this->UserFiles->lexicon('err_file_create');
        }

        return parent::beforeSave();
    }

    public function afterSave()
    {
        $children = $this->object->getMany('Children');
        /* @var UserFile $child */
        foreach ($children as $child) {
            $child->remove();
        }
        $this->object->generateThumbnails();

        return true;
    }

}

return 'modUserFileUploadProcessor';
