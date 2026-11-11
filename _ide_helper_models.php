<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatbotMessage> $messages
 * @property-read int|null $messages_count
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotConversation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotConversation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotConversation query()
 */
	class ChatbotConversation extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \App\Models\ChatbotKnowledgeSource|null $source
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeChunk newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeChunk newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeChunk query()
 */
	class ChatbotKnowledgeChunk extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatbotKnowledgeChunk> $chunks
 * @property-read int|null $chunks_count
 * @property-read \App\Models\User|null $uploader
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeSource newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeSource newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotKnowledgeSource query()
 */
	class ChatbotKnowledgeSource extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \App\Models\ChatbotConversation|null $conversation
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatbotMessage query()
 */
	class ChatbotMessage extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConsultationStaff newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConsultationStaff newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConsultationStaff query()
 */
	class ConsultationStaff extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property int $status
 * @property int $user_id
 * @property string $description
 * @property string $tanggal
 * @property string $start_time
 * @property string $end_time
 * @property string|null $google_event_id
 * @property string|null $google_meet_link
 * @property int|null $rating
 * @property string|null $kritik_saran
 * @property string|null $ringkasan
 * @property string|null $link_dokumentasi
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereEndTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereGoogleEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereGoogleMeetLink($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereKritikSaran($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereLinkDokumentasi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereRingkasan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereStartTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereTanggal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting whereUserId($value)
 */
	class Meeting extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $token
 * @property string $name
 * @property string $group_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramBot whereUpdatedBy($value)
 */
	class TelegramBot extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Role[] $roles
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Permission[] $permissions
 * @method static \Illuminate\Database\Eloquent\Builder|User role($roles, $guard = null)
 * @property int $id
 * @property string|null $google_id
 * @property string $name
 * @property string $email
 * @property string|null $instansi
 * @property string|null $jabatan
 * @property string|null $picture
 * @property string|null $google_access_token
 * @property string|null $google_refresh_token
 * @property string|null $last_login
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $pekerjaan
 * @property int|null $jenis_kelamin
 * @property string|null $tanggal_lahir
 * @property string|null $asal_prov
 * @property string|null $asal_kab
 * @property string|null $no_hp
 * @property string|null $pendidikan
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read int|null $permissions_count
 * @property-read int|null $roles_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAsalKab($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAsalProv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGoogleAccessToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGoogleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGoogleRefreshToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereInstansi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereJabatan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereJenisKelamin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLogin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNoHp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePekerjaan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePendidikan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePicture($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTanggalLahir($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 */
	class User extends \Eloquent {}
}

