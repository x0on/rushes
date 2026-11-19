# Promises

What Rushes always does, or never does. Each was decided once, usually after getting it wrong, and each names the
check that holds Rushes to it: `tests/test_agree.py` fails if a promise here names a check that is not there, and
GitHub runs every check on every push (`sh tests/all.sh`). A line is added here whenever something is settled.

A promise is not taken away by taking its check away: changing a line here is a decision, made with the owner.

## Search

- Search shows everything it found, a page at a time; it never keeps to itself what it thinks is best. — `tests/test_server.php`: “Search: every video found can be reached, a page at a time”
- Cache files are not part of Search at all. — `tests/test_server.php`: “Search: a cache file is never part of it”
- The words offered to narrow a search are single words. — `tests/test_server.php`: “Search: the words offered to narrow it are single words”

- A result is on one page only, and the pages are numbered: videos found by what they show, then what was found by name. — `tests/test_server.php`: “Search: a result is on one page only”
- A click plays a video where it is; Properties stay shut until asked for (right-click, or the i). — `tests/test_agree.py`: “test_search_plays_in_the_grid_and_keeps_properties_shut_until_asked”
- One video plays at a time: a player is stopped before it leaves the page, and Pause pauses. — `tests/test_agree.py`: “test_a_player_is_stopped_before_it_leaves_the_page”

## Describe

- A folder is added by clicking, never only by typing. — `tests/test_folders_offered.sh`: “Choose folder… walks the offered folders by clicking”
- The buttons for folders not on the list are never covered by the box's suggestions. — `tests/test_folders_offered.sh`: “Not on the list yet: above the box”
- The page and the runner count what could not be made the same way. — `tests/test_server.php`: “the page counts what could not be made the way the runner does”

## Drives

- A drive is kept playable and described from its own card: one switch, off until someone turns it on. — `tests/test_server.php`: “switch is off until someone turns it on”
- The switch puts every folder with videos on the list once, never one that is on it already or was left out. — `tests/test_server.php`: “the switch on: folders go on the list whole”
- The switch passes by footage that is waiting to be filed. — `tests/test_server.php`: “the switch on: footage waiting to be filed is passed by”
- Nothing is taken away in the version that brings its replacement: Describe stays while the drive's page learns to do its work. — `tests/test_folders_offered.sh`: “Choose folder… walks the offered folders by clicking”

## Previews and describing

- What is not footage (editing caches, a code project's files, empty files) gets no preview and no description. — `tests/test_mac_runner.py`: “test_what_is_not_footage_is_passed_by_and_a_broken_file_is_tried_once”
- One rule for what is not footage, in the pages, the runner, the helper and the NAS scripts. — `tests/test_agree.py`: “test_what_is_not_footage_is_the_same_rule_everywhere”
- An archive that goes away mid-run is waited for; its files are never counted as failed, nor the folder as done. — `tests/test_mac_runner.py`: “test_an_archive_that_goes_away_is_waited_for_and_never_called_done”

## Safety

- A file someone sends in is never run, whatever its name. — `tests/test_router.sh`: “was run”
- The helper runs only code signed with the release key. — `tests/test_menu.py`: “test_installed_when_newer_and_signed”
