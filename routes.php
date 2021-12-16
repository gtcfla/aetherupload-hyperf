<?php
declare(strict_types=1);
use Hyperf\HttpServer\Router\Router;

Route::post('aetherupload/init',function(\Zen\AetherUpload\Uploader $uploader){

    return $uploader->init();

})->middleware(config('aetherupload.MIDDLEWARE_UPLOAD'));

Route::post('aetherupload/upload',function(\Zen\AetherUpload\Uploader $uploader){

    return $uploader->save();

});

Route::get('aetherupload/display/{resourceName}',function(\Zen\AetherUpload\Uploader $uploader,$resourceName){

    return $uploader->displayResource($resourceName);

})->middleware(config('aetherupload.MIDDLEWARE_DISPLAY'));

Route::get('aetherupload/download/{resourceName}/{newName}',function(\Zen\AetherUpload\Uploader $uploader,$resourceName,$newName){

    return $uploader->downloadResource($resourceName,$newName);

})->middleware(config('aetherupload.MIDDLEWARE_DOWNLOAD'));

