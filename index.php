<!DOCTYPE html>
<html>
    <body>
<?php
/**
 * IMDB TETbot
 * Trivia Extractor and Tweeter
 *
 * What it does - described briefly:
 * Reads IMDB's trivia section, picks a random fact from them, evaluates it based
 * on some criteria, and if those criteria are met, posts it to Twitter as a
 * tweet. Repeats it randomly within set time boundaries.
 *
 * -------------------------
 * TL;DR - How does it work?
 * -------------------------
 * The basics.
 * The script uses external files that contain unique IDs, extracted from the
 * IMDB database. There are two files to be read: "names.txt" and "movies.txt"
 * Both files are filled with IDs like "tt0108778" [movies], "nm0000151" [names].
 * These IDs are separated by newlines, which is important when reading them, so
 * this is a must. Also, the IDs are in most cases unique - if not, that will
 * not break anything, just the odds will be somewhat distorted, therefore the
 * randomness will be manipulated.
 *
 * About the IDs.
 * On IMDB, every single movie and person has a unique ID and it
 * can be seen in the url of a given page. When you build up your own collection,
 * it is actually pretty easy to export them as a CSV file, and from there the
 * needed IDs can be further exported. These IDs will be used later on to rebuild
 * the urls.
 *
 * Pick the file.
 * A file will be selected randomly, either "names.txt" or "movies.txt".
 * From the selected file, an item (ID) will also be picked randomly.
 * 
 * Build the url.
 * The proper url gets created according to the picked item (ID). The url of a 
 * movie's page or a person's bio page are slightly different.
 *
 * Find trivia.
 * Then, when the url is done, hit it up, and search for those trivia items.
 * The searching process happens based on regex rules.
 * There could be a few, tens, hundreds, and could be none. If found, the script
 * will extract all of these trivia from the source code and will store them in
 * an array for later processing.
 * If no trivia found, the ID gets recorded a file, called "no_trivia.txt" and
 * the script will reload itself.
 *
 * Process the findings.
 * Since all of the trivia items were placed into an array, there could be some
 * further processing made. First, shuffle the items, and pick one, again,
 * randomly. This is the final "random-round", but the winner trivia can not be
 * announced just yet.
 * Next, the script cuts out most of the unnecessary HTML link tags based on
 * regex rules. (Note: this cut-out process is a bit flaky, the regex rules here
 * have to be improved. Occassionally, it can't determine the exact end point of
 * a link tag and it results in text bits chopped out.)
 * Now, if the script has gotten this far, it is good to look up the title of the
 * given page, which is a person's name or a movie's title.
 *
 * Every character gets counted and added together. Titles or names and trivia.
 *
 * Build up the tweet.
 * Twitter-friendliness is depending on several factors. First, if the overall
 * character-count is below 15, skip everything and reload the script. It can't
 * be sensible. Then, there are different rules for various character lengths.
 * (Twitter lets 140 characters in a tweet, and its built-in url shortener will 
 * reduce any urls down to 23 character.)
 * So, count everything in every condition and if the number of characters lets
 * it, append one or more hashtags at the end of the tweet, plus the IMDB url of
 * the movie or person.
 *
 * Send and save.
 * Last step is to actually send the tweet to Twitter. For this, TwitterOAuth
 * (https://twitteroauth.com/) is used. Also, if it made it to Twitter, save the
 * tweet in a file, called "saved_trivia.txt".
 *
 * If the end value is not Twitter-friendly the script gets reloaded, and
 * everything starts over.
 *
 * That's it for now.
 *
 * Created by Attila Bakos (abakos.info)
 * 
 * 2016, Plymouth, UK
 */

    # Page reload function
    function reload($delay = 1) {
        $site_url = $_SERVER['REQUEST_URI'];
        header("Refresh: " . $delay . "; URL=$site_url");
    }

    # Refresh the page with randomized timegaps (defined in seconds):
    # reload(mt_rand(1800,7200)); # anytime between 30mins - 2hours
    reload(mt_rand(600,7200)); # anytime between 10mins - 2hours

    # Include the file that contains the authorization details for Twitter:
    include "auth.php";

    # Load the files into an array for further processing:
    $files = array();
        $files[0] = 'names.txt';
        $files[1] = 'movies.txt';

    # Get a random file from the array:
    $random_trivia_file = $files[array_rand($files)];

    # Check which file is used:
    if ($random_trivia_file == $files[0]) {
        $random_trivia_file = file_get_contents($files[0]);
    }
    else {
        $random_trivia_file = file_get_contents($files[1]);
    }

    # Create another array by exploding the random file at new lines.
    # Now, every item from the file will be loaded into this array,
    # however, technically we will need only one of those at the end:
    $random_item_array = explode("\n", $random_trivia_file);

    # Get a random value from the array with which the url can be built,
    # and trim the whitespaces.
    # Example: tt0108778 (in movies.txt), nm0000151 (in names.txt)
    $random_item = $random_item_array[shuffle($random_item_array)];
    $random_item = trim($random_item);

    # Build the url based on which item has been chosen above,
    # then find trivia according to the regular expressions

    # Bio trivia looks like this in the source code:
    # <... class="sode odd | even">trivia here<br />
    # Movie title trivia looks like this in the source code:
    # <... class="sodatext">actual trivia here  </div> (note the two spaces)
    # In the regex pattern: (?<=...) will match things that is preceded by: sodatext">

    # Modifiers: siU
    # s => makes the period to match newlines (by default it doesn't)
    # i => case-insensitive search
    # U => non-greedy match

    # Booleans for later conditional printing
    $is_movie = FALSE;
    $is_human = FALSE;

    # If starts with 'nm...' complete the name/bio link:
    if (preg_match('!nm.*!', $random_item, $random_name)) {
        $build_url = file_get_contents("http://www.imdb.com/name/$random_name[0]/bio");
        $target_url = "http://www.imdb.com/name/$random_item";
        preg_match_all('!(?<=(odd">)|(even">)).*(?=<br />)!siU', $build_url, $matches);
        $is_human = TRUE;
    }
    # Otherwise complete the link to the movie's trivia:
    else {
        $build_url = file_get_contents("http://www.imdb.com/title/$random_item/trivia");
        $target_url = "http://www.imdb.com/title/$random_item";
        preg_match_all('!(?<=sodatext">).*(?=  </div>)!siU', $build_url, $matches);
        $is_movie = TRUE;
    }

    # Count the matches:
    $match_count = count($matches[0]);

    # If no trivia has been found record the ID in a file and reload the script:
    $no_value = 'no_trivia.txt';
    if ($match_count == 0 || $match_count == NULL) {
        echo "<h2>No match or NULL value. Reload.</h2>";
        file_put_contents($no_value, $random_item . PHP_EOL, FILE_APPEND);
        reload();
    }
    else {
        if ($is_human == TRUE) {
            echo "<h2>Random person trivia -- 1 of $match_count:</h2>";
        }
        else { # ($is_movie = TRUE)
            echo "<h2>Random movie trivia -- 1 of $match_count:</h2>";
        }
    }

    # All the matches are stored in an array.
    # Loop through the matches and trim the unnecessary whitespaces:
    foreach ($matches[0] as $key => $value) {
        $matches[0][$key] = trim($value);
    }

    # Get the random trivia from the matches:
    $random_trivia = $matches[0][shuffle($matches[0])];

    # Cut unnecessary HTML tags out:
    $trivia_replace1 = preg_replace('!<a href=".*">!', "", $random_trivia);
    $trivia_replace2 = preg_replace('!(?=.*)</a>(?=.*)!', "", $trivia_replace1);

    # Find item title, that is a person's name or a movie's title:
    $proptitle = preg_match('!(?<=itemprop=\'url\'>).*(?=</a>)!', $build_url, $title_match);

    # Get the length of the title and the trivia and then add them together:
    $title_length = strlen($title_match[0]);
    $trivia_length = strlen($trivia_replace2);
    $char_length = $trivia_length + $title_length + 1;

    # In this file, the trivia has that made it to the final round and is eligible
    # for going on Twitter, will be saved:
    $saved_trivia = 'saved_trivia.txt';

    # Hashtags:
    define('FACT', '#fact');            # 5  char
    define('HOLLYWOOD', '#hollywood');  # 10 char
    define('MOVIE', '#movie');          # 6  char
    define('TRIVIA', '#trivia');        # 7  char
    
    # Check if the end value is Twitter-friendly.
    # (Twitter URL shortener creates 23 character long addresses.)
    # If less than 15 characters, skip it, reload:
    if ($char_length <= 15) {
        $tweet = "Too short.";
        reload();
    }
    # Title + trivia more than 15 and less than 101 chars, append two hashtags and the current URL to it:
    elseif ($char_length >= 16 && $char_length <= 101) {
        $tweet = $title_match[0] . ": " . $trivia_replace2 . " " . MOVIE . " " . TRIVIA . " " . $target_url;
        $statuses = $connection->post("statuses/update", ["status" => $tweet]);
        file_put_contents($saved_trivia, $tweet . PHP_EOL, FILE_APPEND);
    }
    # If the title + trivia is between  101 and 115 chars, tweet with 1 hashtag and a link:
    elseif ($char_length >= 101 && $char_length <= 115) {
        $tweet = $title_match[0] . ": " . $trivia_replace2 . " " . TRIVIA . " " . $target_url;
        $statuses = $connection->post("statuses/update", ["status" => $tweet]);
        file_put_contents($saved_trivia, $tweet . PHP_EOL, FILE_APPEND);
    }
    # If the title + trivia is between  120 and 138 chars, tweet without link and hashtag:
    elseif ($char_length >= 116 && $char_length <= 138) {
        $tweet = $title_match[0] . ": " . $trivia_replace2;
        $statuses = $connection->post("statuses/update", ["status" => $tweet]);
        file_put_contents($saved_trivia, $tweet . PHP_EOL, FILE_APPEND);
    }
    # Or reload...
    else {
        $tweet = "Nothing.";
        reload();
    }

    # Print the things out
    # length:
    echo "<h1>Char count: " . $char_length . "</h1>";
    # title:
    echo "<h1>Title: " . $title_match[0] . "</h1>";
    echo "<hr />";
    # trivia:
    echo "<h2>Random trivia:</h2>";
    echo "<h3>This goes on Twitter:</h3> <h2>" . $tweet . "</h2>";
    echo "<p>" . $trivia_replace2 . "</p>";
    echo "<p>URL in use: <a target=\"_blank\" href=\"" . $target_url . "\">" . $target_url . "</a></p>";
    echo "<p>Trivia saved into file.</p>"
?>
        <!--
        <section style="width: 25%;">
            <a class="twitter-timeline" href="https://twitter.com/abakostbot">Tweets by abakostbot</a> <script async src="//platform.twitter.com/widgets.js" charset="utf-8"></script>
        </section>
        -->
    </body>
</html>