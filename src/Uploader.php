<?php
namespace Gtcfla\AetherUpload;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

class Uploader extends BaseUploader
{
    protected $request;
    protected $response;
    protected $result;
    protected $uploadHead;
    protected $uploadFilePartial;
    static protected $UPLOAD_FILE_DIR;
    static protected $UPLOAD_HEAD_DIR;
    static protected $UPLOAD_PATH;

    public function __construct(RequestInterface $request, ResponseInterface $response)
    {
        $this->request = $request;
        $this->response = $response;
        self::$UPLOAD_PATH = config('aetherupload.UPLOAD_PATH');
        self::$UPLOAD_FILE_DIR = config('aetherupload.UPLOAD_FILE_DIR');
        self::$UPLOAD_HEAD_DIR = config('aetherupload.UPLOAD_HEAD_DIR');
        if(!file_exists(self::$UPLOAD_PATH))
        {
            mkdir(self::$UPLOAD_PATH, 0777, true);
            chmod(self::$UPLOAD_PATH, 0777);
        }
        if(!file_exists(self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR))
        {
            mkdir(self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR, 0777, true);
        }
        if(!file_exists(self::$UPLOAD_PATH.self::$UPLOAD_HEAD_DIR))
        {
            mkdir(self::$UPLOAD_PATH.self::$UPLOAD_HEAD_DIR, 0777, true);
        }
    }

    /**
     * initialize upload and filter the file
     * @return \Illuminate\Http\JsonResponse
     */
    public function init()
    {
        $fileName = $this->request->input('file_name',0);

        $fileSize = $this->request->input('file_size',0);
        $this->result = [
            'error' => 0,
            'chunkSize' => config('aetherupload.CHUNK_SIZE'),
            'uploadBasename' => '',
            'uploadExt' => ''
        ];

        if(!($fileName && $fileSize))
        {
            return $this->reportError('Param is not valid.');
        }

        $uploadExt = strtolower(substr($fileName,strripos($fileName,'.')+1));

        $MAXSIZE = config('aetherupload.UPLOAD_FILE_MAXSIZE') * 1024 * 1024;

        $EXTENSIONS = config('aetherupload.UPLOAD_FILE_EXTENSIONS');

        # ??????????????????
        if($fileSize > $MAXSIZE && $MAXSIZE != 0)
        {
            return $this->reportError('File is too large.');
        }

        # ??????????????????
        if((!in_array($uploadExt,explode(',',$EXTENSIONS)) && $EXTENSIONS != '') || $uploadExt == 'php' || $uploadExt == 'tmp')
        {
            return $this->reportError('File type is not valid.');
        }

        $uploadBasename = $this->generateNewName();

        $this->uploadFilePartial = $this->getUploadFilePartialPath($uploadBasename,$uploadExt);

        $this->uploadHead = $this->getUploadHeadPath($uploadBasename);

        if(!( touch($this->uploadFilePartial) && touch($this->uploadHead)))
        {
            return $this->reportError('Fail to create file.');
        }

        $this->result[ 'uploadBasename' ] = $uploadBasename;

        $this->result[ 'uploadExt' ] = $uploadExt;

        return $this->returnResult();

    }

    /**
     * save the uploaded file
     * @return \Illuminate\Http\JsonResponse
     */
    public function save()
    {

        $chunkTotalCount = $this->request->input('chunk_total',0);# ????????????

        $chunkIndex = $this->request->input('chunk_index',0);# ???????????????

        $uploadBasename = $this->request->input('upload_basename',0);# ???????????????

        $uploadExt = $this->request->input('upload_ext',0);# ???????????????

        $file = $this->request->file('file');

        $this->uploadHead = $this->getUploadHeadPath($uploadBasename);

        $this->uploadFilePartial = $this->getUploadFilePartialPath($uploadBasename,$uploadExt);

        $this->result = [
            'error'    => 0,
            'complete' => 0,
            'uploadName' => ''
        ];

        if(!($chunkTotalCount && $chunkIndex && $uploadExt && $uploadBasename))
        {
            return $this->reportError('Param is not valid.',true);
        }

        if(!(is_file($this->uploadFilePartial) && is_file($this->uploadHead)))
        {
            return $this->reportError('File type is not valid.',true);
        }

        if($file->getError() > 0)
        {
            return $this->reportError($file->getErrorMessage(),true);
        }

        if(!$file->isValid())
        {
            return $this->reportError('File is not uploaded via HTTP POST.',true);
        }

        # ???????????????????????????????????????????????????????????????????????????
        if(is_file($this->uploadHead) && @file_get_contents($this->uploadHead) != $chunkIndex-1)
        {
            return $this->returnResult();
        }

        # ????????????????????????
        if( @file_put_contents($this->uploadFilePartial, @file_get_contents($file->getRealPath()),FILE_APPEND) === FALSE)
        {
            return $this->reportError('Fail to write upload file.',true);
        }

        # ?????????????????????
        if( @file_put_contents($this->uploadHead, $chunkIndex) === FALSE)
        {
            return $this->reportError('Fail to write head file.',true);
        }

        # ????????????????????????
        if($chunkIndex == $chunkTotalCount)
        {
            @unlink($this->uploadHead);

            $uploadFile = str_ireplace('.tmp','',$this->uploadFilePartial);

            if(!@rename($this->uploadFilePartial,$uploadFile))
            {
                return $this->reportError('Fail to rename file.',true);
            }

            $this->result['uploadName'] = basename($uploadFile);

            $this->result['complete'] = 1;
        }

        return $this->returnResult();
    }

    /**
     * display the uploaded file
     * @param $resourceName
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function displayResource($resourceName)
    {
        $uploadedFile = self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR.DIRECTORY_SEPARATOR.$resourceName;

        if(!is_file($uploadedFile)) abort(404);

        return $this->response->download($uploadedFile,'', [],'inline');

    }

    /**
     * download the uploaded file
     * @param $resourceName
     * @param $newName
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadResource($resourceName,$newName)
    {
        $uploadedFile = self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR.DIRECTORY_SEPARATOR.$resourceName;

        if(!is_file($uploadedFile))abort(404);

        $extension = explode('.',$resourceName)[1];

        return $this->response->download($uploadedFile,$newName.'.'.$extension, [],'attachment');

    }

    /**
     * clear temporary files which are created one day ago
     */
    public function cleanUpDir()
    {
        $overTime = strtotime('-1 day');

        $headArr = scandir(self::$UPLOAD_PATH.self::$UPLOAD_HEAD_DIR);

        $uploadArr = scandir(self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR);

        foreach($headArr as $head)
        {
            $headFile = self::$UPLOAD_PATH.self::$UPLOAD_HEAD_DIR.DIRECTORY_SEPARATOR.$head;

            if(!is_file($headFile))
                continue;

            $createTime = substr(pathinfo($headFile,PATHINFO_BASENAME),0,10);

            if($createTime < $overTime)
                @unlink($headFile);
        }

        foreach($uploadArr as $upload)
        {
            $uploadFile = self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR.DIRECTORY_SEPARATOR.$upload;

            if(!is_file($uploadFile) || pathinfo($uploadFile, PATHINFO_EXTENSION) != 'tmp')
                continue;

            $createTime = substr(pathinfo($uploadFile,PATHINFO_BASENAME),0,10);

            if($createTime < $overTime)
                @unlink($uploadFile);

        }

    }

    private function getContentType($fileName)
    {

        $extension = explode('.',$fileName)[1];

        switch($extension)
        {
            case 'mp3' : $contentType = 'audio/mp3';break;
            case 'mp4' : $contentType = 'video/mpeg4';break;
            case 'gif' : $contentType = 'image/gif';break;
            case 'png' : $contentType = 'image/png';break;
            case 'jpg' : $contentType = 'image/jpeg';break;
            default : $contentType = 'application/octet-stream';break;
        }

        return $contentType;

    }

    protected function reportError($message = '',$deleteFiles = false)
    {
        if($deleteFiles) {
            @unlink($this->uploadHead);

            @unlink($this->uploadFilePartial);
        }

        $this->result['error'] = 'Error:'.$message;

        return $this->response->json($this->result);

    }

    protected function returnResult()
    {
        return $this->response->json($this->result);

    }

    protected function generateNewName()
    {
        return time().mt_rand(100,999);

    }

    private function getUploadFilePartialPath($uploadBasename,$uploadExt)
    {
        return self::$UPLOAD_PATH.self::$UPLOAD_FILE_DIR.DIRECTORY_SEPARATOR.$uploadBasename.'.'.$uploadExt.'.tmp';
    }

    private function getUploadHeadPath($uploadBasename)
    {
        return self::$UPLOAD_PATH.self::$UPLOAD_HEAD_DIR.DIRECTORY_SEPARATOR.$uploadBasename.'.head';
    }


}