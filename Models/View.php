<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Models;

final class View extends \Minz_View {

	/** @var 'id'|'date'|'link'|'title'|'rand' */
	public string $currentSort = 'id';

	/** @var 'ASC'|'DESC' */
	public string $currentOrder = 'DESC';

	/**
	 * @var array{id:int,name:string,favicon:string,website:string,
	 *	entries:array<array{id:string,link:string,title:string,dateShort:string,dateRelative:string,
	 *		dateFull:string,snippet:string,compactSnippet:string,detailedSnippet:string,isRead:bool,author:string,tags:string|array<string>,feedId:int}>,
	 *	currentLimit:mixed,currentFontSize:null|string,nbUnread:int,currentHeaderColor:null|string,
	 *	currentMaxHeight:null|string,currentDisplayMode:null|string}[] $feedsData
	 */
	public array $feedsData = [];

	/** @var array<int,\FreshRSS_Category> $categories*/
	public array $categories;

	/** @var array<int,\FreshRSS_Tag> $tags */
	public array $tags;

	public bool $confirmMarkRead;
	public bool $confirmTabDelete;
	public bool $refreshEnabled;
	public int $nbUnreadTags;
	public int $refreshInterval;
	public string $dateFormat;
	public string $dateMode;
	public string $entryClickMode;
	public string $feedUrl;
	public string $getLayoutUrl;
	public string $html_url;
	public string $markFeedReadUrl;
	public string $markReadUrl;
	public string $markTabReadUrl;
	public string $moveFeedUrl;
	public string $rss_title;
	public string $saveFeedSettingsUrl;
	public string $saveLayoutUrl;
	public string $searchAuthorUrl;
	public string $searchTagUrl;
	public string $setActiveTabUrl;
	public string $tabActionUrl;
	public string $viewMode;
	public string $bookmarkUrl;
	public string $refreshFeedsUrl;
	public string $feedSettingsUrl;
	public string $categorySettingsUrl;
}
