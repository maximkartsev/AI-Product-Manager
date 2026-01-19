<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * App\Models\UploadedFile
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $path
 * @property string|null $extension
 * @property int|null $size
 * @property string|null $storage
 * @property string|null $entity_table
 * @property int|null $entity_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $mime_type
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile query()
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereEntityTable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereExtension($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereStorage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UploadedFile whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class UploadedFile extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'type',
        'size',
        'extension',
        'storage',
        'entity_table',
        'entity_id',
        'mime_type',
    ];


    protected $casts = [
        'size' => 'int',
    ];


    public function getLink()
    {
        return route('files.download', ['id' => $this->id]);
    }

    public function delete(): ?bool
    {

        Storage::delete($this->path);

        return parent::delete();
    }


    public function toArray()
    {
        $res = parent::toArray();
        $res['uid'] = $this->id;
        $res['extension'] = \File::extension($this->name);
        $res['url'] = $this->getLink();
        return $res;
    }
}
