<?php
if (!defined('ABSPATH')) exit;
class Aipex_Podcast_ACF {
    public static function register_fields(){
        if (!function_exists('acf_add_local_field_group')) return;
        acf_add_local_field_group(['key'=>'group_aipex_episode','title'=>'Podcast Episode Details','fields'=>[
            ['key'=>'field_audio_source_type','label'=>'Audio Source Type','name'=>'audio_source_type','type'=>'radio','choices'=>['upload'=>'Upload audio file','dropbox'=>'Dropbox link','soundcloud'=>'SoundCloud link'],'default_value'=>'upload','layout'=>'horizontal'],
            ['key'=>'field_audio_file','label'=>'Audio File','name'=>'audio_file','type'=>'file','return_format'=>'id','mime_types'=>'mp3,m4a,wav,ogg'],
            ['key'=>'field_dropbox_url','label'=>'Dropbox URL','name'=>'dropbox_url','type'=>'url'],
            ['key'=>'field_soundcloud_url','label'=>'SoundCloud URL','name'=>'soundcloud_url','type'=>'url'],
            ['key'=>'field_episode_number','label'=>'Episode Number','name'=>'episode_number','type'=>'text'],
            ['key'=>'field_publish_date','label'=>'Publish Date','name'=>'publish_date','type'=>'date_picker','display_format'=>'d/m/Y','return_format'=>'Y-m-d'],
            ['key'=>'field_duration','label'=>'Duration','name'=>'duration','type'=>'text'],
            ['key'=>'field_episode_summary','label'=>'Episode Summary','name'=>'episode_summary','type'=>'wysiwyg','tabs'=>'all','toolbar'=>'basic'],
            ['key'=>'field_main_points','label'=>'Main Points Covered','name'=>'main_points','type'=>'repeater','button_label'=>'Add Point','sub_fields'=>[
                ['key'=>'field_main_point_text','label'=>'Point','name'=>'point','type'=>'text'],
                ['key'=>'field_main_point_desc','label'=>'Description','name'=>'description','type'=>'textarea','rows'=>2]
            ]],
            ['key'=>'field_transcript','label'=>'Transcript','name'=>'transcript','type'=>'wysiwyg','tabs'=>'text','toolbar'=>'basic'],
            ['key'=>'field_ai_summary','label'=>'AI Summary','name'=>'ai_summary','type'=>'textarea'],
            ['key'=>'field_series','label'=>'Series','name'=>'series','type'=>'relationship','post_type'=>['aipex_series'],'return_format'=>'id','max'=>1],
            ['key'=>'field_presenters','label'=>'Presenters','name'=>'presenters','type'=>'relationship','post_type'=>['aipex_presenter'],'return_format'=>'id'],
            ['key'=>'field_guests','label'=>'Guests','name'=>'guests','type'=>'relationship','post_type'=>['aipex_guest'],'return_format'=>'id'],
            ['key'=>'field_sponsors','label'=>'Sponsors','name'=>'sponsors','type'=>'relationship','post_type'=>['aipex_sponsor'],'return_format'=>'id'],
            ['key'=>'field_featured_episode','label'=>'Featured Episode','name'=>'featured_episode','type'=>'true_false','ui'=>1],
        ],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'aipex_podcast']]]]);
        acf_add_local_field_group(['key'=>'group_aipex_series','title'=>'Show / Series Details','fields'=>[
            ['key'=>'field_series_overview','label'=>'Series Overview','name'=>'series_overview','type'=>'wysiwyg','toolbar'=>'basic'],
            ['key'=>'field_series_main_points','label'=>'Main Topics Covered In Series','name'=>'series_main_points','type'=>'repeater','button_label'=>'Add Topic','sub_fields'=>[['key'=>'field_series_point','label'=>'Point','name'=>'point','type'=>'text']]],
            ['key'=>'field_series_episode_summaries','label'=>'Episode Summaries','name'=>'series_episode_summaries','type'=>'repeater','button_label'=>'Add Episode Summary','sub_fields'=>[
                ['key'=>'field_series_episode_link','label'=>'Episode','name'=>'episode','type'=>'post_object','post_type'=>['aipex_podcast'],'return_format'=>'id'],
                ['key'=>'field_series_episode_name','label'=>'Episode Name','name'=>'episode_name','type'=>'text'],
                ['key'=>'field_series_episode_summary','label'=>'Summary','name'=>'summary','type'=>'textarea','rows'=>3]
            ]],
            ['key'=>'field_series_sponsors','label'=>'Show Sponsors','name'=>'series_sponsors','type'=>'relationship','post_type'=>['aipex_sponsor'],'return_format'=>'id'],
            ['key'=>'field_series_rss_url','label'=>'RSS URL','name'=>'rss_url','type'=>'url'],['key'=>'field_series_spotify_url','label'=>'Spotify URL','name'=>'spotify_url','type'=>'url'],['key'=>'field_series_apple_url','label'=>'Apple Podcasts URL','name'=>'apple_url','type'=>'url'],['key'=>'field_series_youtube_url','label'=>'YouTube URL','name'=>'youtube_url','type'=>'url'],['key'=>'field_series_amazon_url','label'=>'Amazon URL','name'=>'amazon_url','type'=>'url'],['key'=>'field_series_pocketcasts_url','label'=>'Pocket Casts URL','name'=>'pocketcasts_url','type'=>'url'],
        ],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'aipex_series']]]]);
        acf_add_local_field_group(['key'=>'group_aipex_presenter','title'=>'Presenter Details','fields'=>[
            ['key'=>'field_presenter_about','label'=>'About Presenter','name'=>'presenter_about','type'=>'wysiwyg','toolbar'=>'basic'],
            ['key'=>'field_presenter_website','label'=>'Website','name'=>'website','type'=>'url'],['key'=>'field_presenter_facebook','label'=>'Facebook','name'=>'facebook','type'=>'url'],['key'=>'field_presenter_x','label'=>'X','name'=>'x_url','type'=>'url'],['key'=>'field_presenter_instagram','label'=>'Instagram','name'=>'instagram','type'=>'url'],['key'=>'field_presenter_tiktok','label'=>'TikTok','name'=>'tiktok','type'=>'url'],['key'=>'field_presenter_pinterest','label'=>'Pinterest','name'=>'pinterest','type'=>'url'],['key'=>'field_presenter_linkedin','label'=>'LinkedIn','name'=>'linkedin','type'=>'url'],['key'=>'field_presenter_soundcloud','label'=>'SoundCloud','name'=>'soundcloud','type'=>'url'],['key'=>'field_presenter_phone','label'=>'Contact Number','name'=>'contact_number','type'=>'text'],['key'=>'field_presenter_email','label'=>'Contact Email','name'=>'contact_email','type'=>'email'],
            ['key'=>'field_presenter_rss_url','label'=>'RSS URL','name'=>'rss_url','type'=>'url'],['key'=>'field_presenter_spotify_url','label'=>'Spotify URL','name'=>'spotify_url','type'=>'url'],['key'=>'field_presenter_apple_url','label'=>'Apple Podcasts URL','name'=>'apple_url','type'=>'url'],['key'=>'field_presenter_youtube_url','label'=>'YouTube URL','name'=>'youtube_url','type'=>'url'],['key'=>'field_presenter_amazon_url','label'=>'Amazon URL','name'=>'amazon_url','type'=>'url'],['key'=>'field_presenter_pocketcasts_url','label'=>'Pocket Casts URL','name'=>'pocketcasts_url','type'=>'url'],
            ['key'=>'field_presenter_user','label'=>'Linked WordPress/BuddyPress User','name'=>'linked_user','type'=>'user','return_format'=>'id'],
        ],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'aipex_presenter']]]]);
    }
}
