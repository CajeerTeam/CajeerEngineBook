<?php
declare(strict_types=1);
namespace CajeerBook;

/** Document language and translation identity are persisted in ordinary page snapshots. */
final class Locale
{
    public const LANGUAGES=['en'=>'English','ru'=>'Русский'];
    private static string $ui='en';
    public static function valid(mixed $locale):bool{return is_string($locale)&&isset(self::LANGUAGES[$locale]);}
    public static function require(mixed $locale):string{if(!self::valid($locale))throw new \InvalidArgumentException('Unsupported document language.');return $locale;}
    public static function language(array $page):string{return self::require($page['locale']??'en');}
    public static function metadata(array $page):array{return array_intersect_key($page,array_flip(['locale','translation_group','source_digest']));}
    public static function group(array $page,string $id):string{return $page['translation_group']??$id;}
    public static function digest(array $page):string{return hash('sha256',json_encode([$page['title'],$page['section'],$page['body']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    public static function validateSnapshot(array $page):void{
        if(array_key_exists('locale',$page))self::require($page['locale']);
        $linked=array_key_exists('translation_group',$page);$digest=array_key_exists('source_digest',$page);
        if($linked!==$digest||($linked&&(!isset($page['locale'])||!is_string($page['translation_group'])||!preg_match('/\A[a-f0-9]{32}\z/D',$page['translation_group'])||!is_string($page['source_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$page['source_digest']))))throw new \InvalidArgumentException('Invalid translation metadata.');
    }
    /** Separate uniqueness constraints for draft and public state; no draft association is used by Reader. */
    public static function validate(array $db):void{
        $seen=[];$byId=[];foreach($db['pages'] as $key=>$entry)$byId[PageTree::scope($key).'|'.PageTree::id($key,$entry)]=$entry;
        foreach($db['pages'] as $key=>$entry){$id=PageTree::id($key,$entry);$scope=PageTree::scope($key);
            foreach(['draft','published'] as $state){$p=$entry[$state];if($p===null)continue;self::validateSnapshot($p);
                $group=self::group($p,$id);if(isset($p['translation_group'])){if($group===$id)throw new \InvalidArgumentException('Translation cannot reference itself.');$source=$byId[$scope.'|'.$group][$state]??null;if($source!==null&&isset($source['translation_group']))throw new \InvalidArgumentException('Translation must reference an original document.');}
                $identity=$scope.'|'.$group.'|'.self::language($p).'|'.$state;
                if(isset($seen[$identity]))throw new \InvalidArgumentException('Duplicate language in translation group.');$seen[$identity]=true;
            }
        }
    }
    public static function source(array $db,string $scope,string $id):?array{
        foreach($db['pages'] as $key=>$entry)if(PageTree::scope($key)===$scope&&PageTree::id($key,$entry)===$id&&$entry['published']!==null&&!isset($entry['published']['translation_group']))return $entry['published'];
        return null;
    }
    /** Accept only an exact published source, in the same book/version, under the write lock. */
    public static function attach(array $db,string $key,string $locale,string $sourceId,string $expected):array{
        self::require($locale);$scope=PageTree::scope($key);$source=self::source($db,$scope,$sourceId);
        if(!$source||!hash_equals(self::digest($source),$expected))throw new \DomainException('Published source changed or is unavailable. Reload translations.');
        foreach($db['pages'] as $k=>$e)if(PageTree::scope($k)===$scope&&PageTree::id($k,$e)===$sourceId&&self::language($e['draft'])!==self::language($source))throw new \DomainException('Publish the source language change before creating translations.');
        if(self::language($source)===$locale)throw new \InvalidArgumentException('Translation language must differ from source.');
        foreach($db['pages'] as $k=>$e)if(PageTree::scope($k)===$scope)foreach(['draft','published'] as $state){$p=$e[$state];if($p!==null&&self::group($p,PageTree::id($k,$e))===$sourceId&&self::language($p)===$locale)throw new \DomainException('This language already has a page in the translation group.');}
        return ['locale'=>$locale,'translation_group'=>$sourceId,'source_digest'=>$expected];
    }
    /** A standalone document may be retagged, but a group cannot change identity. */
    public static function canRetag(array $db,string $key):bool{
        $entry=$db['pages'][$key]??null;if(!$entry)return false;$id=PageTree::id($key,$entry);$scope=PageTree::scope($key);
        foreach(['draft','published'] as $state)if(isset($entry[$state]['translation_group']))return false;
        foreach($db['pages'] as $k=>$e)if(PageTree::scope($k)===$scope)foreach(['draft','published'] as $state)if(($e[$state]['translation_group']??null)===$id)return false;
        return true;
    }
    /** Only already-authorized published pages may be supplied. */
    public static function variants(array $pages,string $key):array{
        $p=$pages[$key]??null;if(!$p||!isset($p['id']))return [];$group=self::group($p,$p['id']);$scope=PageTree::scope($key);$out=[];
        foreach($pages as $k=>$v)if(isset($v['id'])&&PageTree::scope($k)===$scope&&self::group($v,$v['id'])===$group)$out[self::language($v)]=$k;
        ksort($out);return $out;
    }
    public static function pages(array $pages,string $locale):array{return $locale===''?$pages:array_filter($pages,static fn($p)=>self::language($p)===$locale);}
    public static function options(string $selected,bool $all=false):string{
        $out=$all?'<option value=""'.($selected===''?' selected':'').'>'.Markdown::e(self::t('All languages')).'</option>':'';
        foreach(self::LANGUAGES as $code=>$label)$out.='<option value="'.$code.'"'.($selected===$code?' selected':'').'>'.Markdown::e($label).'</option>';return $out;
    }
    public static function setUi(string $lang):void{self::$ui=self::valid($lang)?$lang:'en';}
    public static function ui():string{return self::$ui;}
    public static function translate(string $lang,string $english):string{return $lang==='ru'?(self::RU[$english]??$english):$english;}
    public static function t(string $english):string{return self::translate(self::$ui,$english);}
    public static function uiLinks():string{
        $params=[];foreach(['q','book','version','limit','offset','locale','edit','source','scope','new_under','key','page','id'] as $k)if(is_string($_GET[$k]??null)&&strlen($_GET[$k])<=512)$params[$k]=$_GET[$k];
        $out='<span class="locale-switch" aria-label="'.Markdown::e(self::t('Interface language')).'">';foreach(self::LANGUAGES as $code=>$label)$out.='<a lang="'.$code.'" href="?'.Markdown::e(http_build_query($params+['ui'=>$code],'','&',PHP_QUERY_RFC3986)).'"'.($code===self::$ui?' aria-current="true"':'').'>'.Markdown::e(strtoupper($code)).'</a> ';return $out.'</span>';
    }
    private const RU=[
        'Documentation'=>'Документация','Search documentation'=>'Поиск по документации','Search'=>'Поиск','Studio'=>'Редактор','Team'=>'Команда','Workspaces'=>'Рабочие пространства','Organizations'=>'Организации','Account'=>'Аккаунт','Toggle theme'=>'Переключить тему','Self-hosted documentation'=>'Документация на вашем сервере','Interface language'=>'Язык интерфейса','Language'=>'Язык','All languages'=>'Все языки','Translations'=>'Переводы','On this page'=>'На этой странице','Versions'=>'Версии','Read documentation →'=>'Читать документацию →','Documentation, on your terms.'=>'Документация на ваших условиях.','A self-hosted knowledge base for your products, APIs and teams.'=>'База знаний для ваших продуктов, API и команд.','Book'=>'Книга','Version'=>'Версия','Previous'=>'Назад','Next'=>'Далее','Results'=>'Результаты','Similar titles or paths (no full-text matches).'=>'Похожие заголовки или пути (нет полнотекстовых совпадений).','Documentation Studio'=>'Редактор документации','Drafts and published snapshots are separate.'=>'Черновики и опубликованные снимки хранятся отдельно.','New page'=>'Новая страница','Edit page'=>'Редактировать страницу','Create page'=>'Создать страницу','Book slug'=>'Ключ книги','Page path'=>'Путь страницы','Title'=>'Заголовок','Section'=>'Раздел','Draft navigation order'=>'Порядок в черновике','Save draft'=>'Сохранить черновик','Publish'=>'Опубликовать','Unpublish'=>'Снять с публикации','Delete'=>'Удалить','Page history'=>'История страницы','Saved draft preview'=>'Просмотр сохранённого черновика','Review saved draft'=>'Отправить черновик на проверку','Revision history'=>'История изменений','Reviews'=>'Согласования','Page tree'=>'Дерево страниц','Books'=>'Книги','Media library'=>'Медиатека','Import Markdown'=>'Импорт Markdown','Team dashboard'=>'Команда','Search Inspector'=>'Диагностика поиска','Static Export'=>'Статический экспорт','Back to Studio'=>'Вернуться в редактор','Source document'=>'Исходный документ','Create translation'=>'Создать перевод','Translation Studio'=>'Редактор переводов','Published'=>'Опубликовано','Draft'=>'Черновик','Up to date'=>'Актуален','Source changed'=>'Исходник изменён','Source unavailable'=>'Исходник недоступен','Mark source reviewed'=>'Подтвердить проверку исходника','Open editor'=>'Открыть редактор','Translation path'=>'Путь перевода','Translation title'=>'Заголовок перевода','Translation section'=>'Раздел перевода','Translation Markdown'=>'Markdown перевода','No published source documents.'=>'Нет опубликованных исходных документов.','Translation language'=>'Язык перевода','Published source text'=>'Опубликованный исходный текст','Compare the published source with your translation before confirming.'=>'Сравните опубликованный исходник с переводом перед подтверждением.','Save a translation draft, then publish it using the normal review workflow.'=>'Сохраните черновик перевода, затем опубликуйте его через обычное согласование.','Page not found'=>'Страница не найдена','This page is missing or not published.'=>'Страница отсутствует или не опубликована.','Sign in required'=>'Требуется вход','Sign in to Studio'=>'Войти в редактор','Copy version to drafts'=>'Копировать версию в черновики','Copy published pages'=>'Копировать опубликованные страницы','Source version'=>'Исходная версия','New version'=>'Новая версия','Move or rename pages in the page tree. Publish tree to apply navigation order and URLs.'=>'Перемещайте и переименовывайте страницы в дереве. Опубликуйте дерево, чтобы применить порядок и URL.','Revision note (optional, manual save/publish)'=>'Примечание к изменению (необязательно)','What changed?'=>'Что изменилось?',
        'Markdown'=>'Markdown',
        'Visual'=>'Визуальный режим',
        'Preview'=>'Предпросмотр',
        'Editor mode'=>'Режим редактора',
        'Formatting'=>'Форматирование',
        'Block style'=>'Стиль блока',
        'Paragraph'=>'Абзац',
        'Heading'=>'Заголовок',
        'Quote'=>'Цитата',
        'Bold'=>'Жирный',
        'Italic'=>'Курсив',
        'Strike'=>'Зачёркнутый',
        'Inline code'=>'Код в строке',
        'Bullets'=>'Маркированный список',
        'Bullet list'=>'Маркированный список',
        'Numbered list'=>'Нумерованный список',
        'Indent'=>'Увеличить отступ',
        'Outdent'=>'Уменьшить отступ',
        'Task list'=>'Список задач',
        'Link'=>'Ссылка',
        'Unlink'=>'Убрать ссылку',
        'Undo'=>'Отменить',
        'Redo'=>'Повторить',
        'Insert image'=>'Вставить изображение',
        'Insert attachment'=>'Вставить вложение',
        'Copy Markdown'=>'Копировать Markdown',
        'Download Markdown'=>'Скачать Markdown',
        'Draft loaded'=>'Черновик загружен',
        'Add block'=>'Добавить блок',
        'New block type'=>'Тип нового блока',
        'Code block'=>'Блок кода',
        'Mermaid diagram'=>'Диаграмма Mermaid',
        'Table'=>'Таблица',
        'Image'=>'Изображение',
        'Attachment'=>'Вложение',
        'Callout'=>'Выноска',
        'Cards'=>'Карточки',
        'Tabs'=>'Вкладки',
        'Code tabs'=>'Вкладки кода',
        'Accordion'=>'Аккордеон',
        'Steps'=>'Шаги',
        'Divider'=>'Разделитель',
        'Recover local changes'=>'Восстановить локальные изменения',
        'Open current server version'=>'Открыть текущую версию на сервере',
        'Download or copy your Markdown before leaving. Open the current server version separately to compare; no changes are sent automatically.'=>'Скачайте или скопируйте Markdown перед уходом. Откройте текущую серверную версию отдельно для сравнения; изменения автоматически не отправляются.',
        'Edit a block directly. Use the toolbar to format text, or add a block below. Edit tables directly, or paste spreadsheet cells. Insert images and attachments from your media library. Add callouts, cards, tabs, accordions and steps with structured properties and Markdown or visual text. Use the block grip or Move block buttons to reorder blocks; Alt+Shift+Up/Down moves the focused block. Mermaid diagrams have live preview. Unsupported inline media remains intact in Markdown mode.'=>'Редактируйте блоки напрямую. Форматируйте текст с панели инструментов, добавляйте блоки, таблицы, изображения и вложения. Для выносок, карточек, вкладок, аккордеонов и шагов доступны структурированные свойства. Перемещайте блоки перетаскиванием или Alt+Shift+Вверх/Вниз. Диаграммы Mermaid поддерживают предпросмотр. Неподдерживаемые элементы сохраняются в Markdown.',
        'Manage page tree →'=>'Управлять деревом страниц →',
        'Published · unpublished changes'=>'Опубликовано · есть изменения',
        'Sign in'=>'Войти',
        'Username'=>'Имя пользователя',
        'Password'=>'Пароль',
        'Recover access'=>'Восстановить доступ',
        'Recovery token'=>'Токен восстановления',
        'New password'=>'Новый пароль',
        'Confirm password'=>'Подтвердите пароль',
        'Reset password'=>'Сбросить пароль',
        'Your account'=>'Ваш аккаунт',
        'User management'=>'Управление пользователями',
        'Name'=>'Имя',
        'Email'=>'Email',
        'Save profile'=>'Сохранить профиль',
        'Change password'=>'Изменить пароль',
        'Current password'=>'Текущий пароль',
        'Sign out'=>'Выйти',
        'Corporate sign-in complete'=>'Корпоративный вход завершён',
        'Continue to your workspaces'=>'Перейти к рабочим пространствам',
        'Orphan translations'=>'Переводы без опубликованного исходника',
        'No translation available'=>'Перевод недоступен',
        'Read original'=>'Читать исходник',
        'Open Reader search'=>'Открыть поиск',
        'Query'=>'Запрос',
        'Audience'=>'Аудитория',
        'Authorized member'=>'Участник с доступом',
        'Anonymous public Reader'=>'Анонимный читатель',
        'Inspect query'=>'Проверить запрос',
        'Git Sync'=>'Синхронизация Git',
        'Webhooks & Automation'=>'Вебхуки и автоматизация',
        'CI/CD Publishing'=>'Публикация CI/CD',
        'Download JSON backup'=>'Скачать JSON-резервную копию',
        'Content backup (.tar)'=>'Резервная копия контента (.tar)',
        'OpenAPI Reference'=>'Справочник OpenAPI',
        'Review required. Save the draft, then submit its saved revision for approval.'=>'Требуется согласование. Сохраните черновик, затем отправьте ревизию на проверку.',
        'Open reviews'=>'Открыть согласования',
        'Try API in Playground'=>'Попробовать API',
        'SDK & Code Examples'=>'SDK и примеры кода',
        'Source changed or unavailable'=>'Исходник изменён или недоступен',
        'Change draft language'=>'Изменить язык черновика','Publish the draft to change the public language.'=>'Опубликуйте черновик, чтобы изменить публичный язык.'
    ];
}
