<?php

declare(strict_types=1);

namespace TripBuilder\Database;

/**
 * Single source of truth for database table names.
 */
enum Table: string
{
    case AdminEvents = 'admin_events';
    case Aircraft = 'aircraft';
    case AircraftCabins = 'aircraft_cabins';
    case Airlines = 'airlines';
    case Airports = 'airports';
    case Articles = 'articles';
    case ArticleCategories = 'article_categories';
    case ArticleCategoryTranslations = 'article_category_translations';
    case ArticleTranslations = 'article_translations';
    case ArticleVotes = 'article_votes';
    case Bookings = 'bookings';
    case BookingEvents = 'booking_events';
    case BookingPassengers = 'booking_passengers';
    case BookingRemarks = 'booking_remarks';
    case BookingTickets = 'booking_tickets';
    case CityImages = 'city_images';
    case Countries = 'countries';
    case CurrencyRates = 'currency_rates';
    case FareBrands = 'fare_brands';
    case Flights = 'flights';
    case Posts = 'posts';
    case PostTranslations = 'post_translations';
    case PostImages = 'post_images';
    case PostVotes = 'post_votes';
    case PostTagMap = 'post_tag_map';
    case PostTagTranslations = 'post_tag_translations';
    case RateLimits = 'rate_limits';
    case RouteDayPrice = 'route_day_price';
    case RoutePriceBuild = 'route_price_build';
    case RouteWatches = 'route_watches';
    case RouteWatchAlertsSent = 'route_watch_alerts_sent';
    case ScheduledJobs = 'scheduled_jobs';
    case ScheduleRuns = 'schedule_runs';
    case ScheduleRunHistory = 'schedule_run_history';
    case Search = 'search';
    case SearchCandidates = 'search_candidates';
    case SearchDailyCounts = 'search_daily_counts';
    case Settings = 'settings';
    case SettingChanges = 'setting_changes';
    case Subscribers = 'subscribers';
}
