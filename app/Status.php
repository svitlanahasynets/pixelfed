<?php

namespace App;

use Auth, Cache, Hashids, Storage;
use Illuminate\Database\Eloquent\Model;
use Pixelfed\Snowflake\HasSnowflakePrimary;
use App\Http\Controllers\StatusController;
use Illuminate\Database\Eloquent\SoftDeletes;

class Status extends Model
{
    use HasSnowflakePrimary, SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    protected $fillable = ['profile_id', 'visibility', 'in_reply_to_id', 'reblog_of_id', 'type'];

    const STATUS_TYPES = [
        'text',
        'photo',
        'photo:album',
        'video',
        'video:album',
        'photo:video:album',
        'share',
        'reply',
        'story',
        'story:reply',
        'story:reaction',
        'story:live',
        'loop'
    ];

    const MAX_MENTIONS = 5;

    const MAX_HASHTAGS = 30;

    const MAX_LINKS = 2;

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }

    public function firstMedia()
    {
        return $this->hasMany(Media::class)->orderBy('order', 'asc')->first();
    }

    public function viewType()
    {
        if($this->type) {
            return $this->type;
        }
        return $this->setType();
    }

    public function setType()
    {
        if(in_array($this->type, self::STATUS_TYPES)) {
            return $this->type;
        }
        $mimes = $this->media->pluck('mime')->toArray();
        $type = StatusController::mimeTypeCheck($mimes);
        if($type) {
            $this->type = $type;
            $this->save();
            return $type;
        }
    }

    public function thumb($showNsfw = false)
    {
        return Cache::remember('status:thumb:'.$this->id, now()->addMinutes(15), function() use ($showNsfw) {
            $type = $this->type ?? $this->setType();
            $is_nsfw = !$showNsfw ? $this->is_nsfw : false;
            if ($this->media->count() == 0 || $is_nsfw || !in_array($type,['photo', 'photo:album', 'video'])) {
                return url(Storage::url('public/no-preview.png'));
            }

            return url(Storage::url($this->firstMedia()->thumbnail_path));
        });
    }

    public function url()
    {
        if($this->uri) {
            return $this->uri;
        } else {
            $id = $this->id;
            $username = $this->profile->username;
            $path = url(config('app.url')."/p/{$username}/{$id}");
            return $path;
        }
    }

    public function permalink($suffix = '/activity')
    {
        $id = $this->id;
        $username = $this->profile->username;
        $path = config('app.url')."/p/{$username}/{$id}{$suffix}";

        return url($path);
    }

    public function editUrl()
    {
        return $this->url().'/edit';
    }

    public function mediaUrl()
    {
        $media = $this->firstMedia();
        $path = $media->media_path;
        $hash = is_null($media->processed_at) ? md5('unprocessed') : md5($media->created_at);
        if(config('pixelfed.cloud_storage') == true) {
            $url = Storage::disk(config('filesystems.cloud'))->url($path)."?v={$hash}";
        } else {
            $url = Storage::url($path)."?v={$hash}";
        }

        return url($url);
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    public function liked() : bool
    {
        if(Auth::check() == false) {
            return false;
        }
        $user = Auth::user();
        $id = $this->id;
        return Cache::remember('status:'.$this->id.':likedby:userid:'.$user->id, now()->addHours(30), function() use($user, $id) {
            $profile = $user->profile;
            return Like::whereProfileId($profile->id)->whereStatusId($id)->count();
        });
    }

    public function likedBy()
    {
        return $this->hasManyThrough(
            Profile::class,
            Like::class,
            'status_id',
            'id',
            'id',
            'profile_id'
        );
    }

    public function comments()
    {
        return $this->hasMany(self::class, 'in_reply_to_id');
    }

    public function bookmarked()
    {
        if (!Auth::check()) {
            return false;
        }
        $profile = Auth::user()->profile;

        return Bookmark::whereProfileId($profile->id)->whereStatusId($this->id)->count();
    }

    public function shares()
    {
        return $this->hasMany(self::class, 'reblog_of_id');
    }

    public function shared() : bool
    {
        if(Auth::check() == false) {
            return false;
        }
        $user = Auth::user();
        $id = $this->id;
        return Cache::remember('status:'.$this->id.':sharedby:userid:'.$user->id, now()->addHours(30), function() use($user, $id) {
            $profile = $user->profile;
            return self::whereProfileId($profile->id)->whereReblogOfId($id)->count();
        });
    }

    public function sharedBy()
    {
        return $this->hasManyThrough(
            Profile::class,
            Status::class,
            'reblog_of_id',
            'id',
            'id',
            'profile_id'
        );
    }

    public function parent()
    {
        $parent = $this->in_reply_to_id ?? $this->reblog_of_id;
        if (!empty($parent)) {
            return $this->findOrFail($parent);
        } else {
            return false;
        }
    }

    public function conversation()
    {
        return $this->hasOne(Conversation::class);
    }

    public function hashtags()
    {
        return $this->hasManyThrough(
        Hashtag::class,
        StatusHashtag::class,
        'status_id',
        'id',
        'id',
        'hashtag_id'
      );
    }

    public function mentions()
    {
        return $this->hasManyThrough(
        Profile::class,
        Mention::class,
        'status_id',
        'id',
        'id',
        'profile_id'
      );
    }

    public function reportUrl()
    {
        return route('report.form')."?type=post&id={$this->id}";
    }

    public function toActivityStream()
    {
        $media = $this->media;
        $mediaCollection = [];
        foreach ($media as $image) {
            $mediaCollection[] = [
          'type'      => 'Link',
          'href'      => $image->url(),
          'mediaType' => $image->mime,
        ];
        }
        $obj = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'type'     => 'Image',
        'name'     => null,
        'url'      => $mediaCollection,
      ];

        return $obj;
    }

    public function replyToText()
    {
        $actorName = $this->profile->username;

        return "{$actorName} ".__('notification.commented');
    }

    public function replyToHtml()
    {
        $actorName = $this->profile->username;
        $actorUrl = $this->profile->url();

        return "<a href='{$actorUrl}' class='profile-link'>{$actorName}</a> ".
          __('notification.commented');
    }

    public function shareToText()
    {
        $actorName = $this->profile->username;

        return "{$actorName} ".__('notification.shared');
    }

    public function shareToHtml()
    {
        $actorName = $this->profile->username;
        $actorUrl = $this->profile->url();

        return "<a href='{$actorUrl}' class='profile-link'>{$actorName}</a> ".
          __('notification.shared');
    }

    public function recentComments()
    {
        return $this->comments()->orderBy('created_at', 'desc')->take(3);
    }

    public function toActivityPubObject()
    {
        if($this->local == false) {
            return;
        }
        $profile = $this->profile;
        $to = $this->scopeToAudience('to');
        $cc = $this->scopeToAudience('cc');
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'    => $this->permalink(),
            'type'  => 'Create',
            'actor' => $profile->permalink(),
            'published' => $this->created_at->format('c'),
            'to' => $to,
            'cc' => $cc,
            'object' => [
                'id' => $this->url(),
                'type' => 'Note',
                'summary' => null,
                'inReplyTo' => null,
                'published' => $this->created_at->format('c'),
                'url' => $this->url(),
                'attributedTo' => $this->profile->url(),
                'to' => $to,
                'cc' => $cc,
                'sensitive' => (bool) $this->is_nsfw,
                'content' => $this->rendered,
                'attachment' => $this->media->map(function($media) {
                    return [
                        'type' => 'Document',
                        'mediaType' => $media->mime,
                        'url' => $media->url(),
                        'name' => null
                    ];
                })->toArray()
            ]
        ];
    }

    public function scopeToAudience($audience)
    {
        if(!in_array($audience, ['to', 'cc']) || $this->local == false) { 
            return;
        }
        $res = [];
        $res['to'] = [];
        $res['cc'] = [];
        $scope = $this->scope;
        $mentions = $this->mentions->map(function ($mention) {
            return $mention->permalink();
        })->toArray();

        switch ($scope) {
            case 'public':
                $res['to'] = [
                    "https://www.w3.org/ns/activitystreams#Public"
                ];
                $res['cc'] = array_merge([$this->profile->permalink('/followers')], $mentions);
                break;

            case 'unlisted':
                break;

            case 'private':
                break;

            case 'direct':
                break;
        }
        return $res[$audience];
    }

}
