<?php

namespace App\Traits;

use App\Constants\FileTypeConstants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

trait FileUploadTrait
{
    public function uploadImage(Request $request, $folderName = null)
    {
        $image = $request->file('image');
        $originalName = $image->getClientOriginalName();
        $extension = $image->getClientOriginalExtension();
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . date('Y.m.d_His') . '.' . $extension;

        // $path = $image->storeAs($folderName, $newName, 'images');

        $path = $image->storeAs($folderName, $newName, 'images');

        return $path;
    }

    public function uploadVideo(Request $request, $folderName = null)
    {
        $video = $request->file('video');
        $originalName = $video->getClientOriginalName();
        $extension = $video->getClientOriginalExtension();
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . date('Y.m.d_His') . '.' . $extension;

        // $path = $video->storeAs($folderName, $newName, 'videos');

        $path = $video->storeAs($folderName, $newName, 'videos');

        return $path;
    }

    public function uploadFile(Request $request, $folderName = null)
    {
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . date('Y.m.d_His') . '.' . $extension;

        // $path = $file->storeAs($folderName, $newName, 'files');

        $path = $file->storeAs($folderName, $newName, 'files');

        return $path;
    }

    public function deleteFile($filePath, $fileType)
    {
        $disk = null;
        switch ($fileType) {
            case FileTypeConstants::FILE_TYPE_IMAGE:
                $disk = 'images';
                break;
            case FileTypeConstants::FILE_TYPE_VIDEO:
                $disk = 'videos';
                break;
            case FileTypeConstants::FILE_TYPE_FILE:
                $disk = 'files';
                break;
            case 'services':
                $disk = 'services';
                break;
            case 'qr':
                $disk = 'qr';
                break;
        }

        if ($disk) {
            // Convert the absolute path to a relative path
            // $relativePath = str_replace(public_path('services') . '/', '', $filePath);

            if (Storage::disk($disk)->exists($filePath)) {
                Storage::disk($disk)->delete($filePath);
                return true;
            }
        }

        return false;
    }



}
