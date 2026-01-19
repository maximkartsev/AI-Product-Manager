<?php

namespace App\Http\Controllers;

use App\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;


class UploadedFileController extends BaseController
{
    public function download($id)
    {
        $file = UploadedFile::find($id);
        if (!$file) {
            return $this->sendError(trans('File not found'));
        }

        $path = $file->path;

        $headers = [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $file->name . '"',
        ];

        if (strpos($path, '/') === 0) {
            $downloadPath = $path;
        }else{
            $downloadPath = Storage::path($path);
        }
        return response()->download($downloadPath, $file->name, $headers);
    }


    public function preview($id)
    {
        $file = UploadedFile::find($id);
        if (!$file) {
            abort(404, 'File not found');
        }

        $path = $file->path;

        if (strpos($path, '/') === 0) {
            $filePath = $path;
        } else {
            $filePath = Storage::path($path);
        }

        if (!file_exists($filePath)) {
            abort(404, 'File not found');
        }

        $contents = file_get_contents($filePath);

        return response($contents)
            ->header('Content-Type', $file->mime_type)
            ->header('Content-Disposition', 'inline; filename="' . $file->name . '"')
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
