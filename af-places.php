<?php
/**
 * Airport First — Sri Lankan places with coordinates, for a server-side route
 * estimate when the page could not send one (an old cached page, no network
 * for OSRM/Photon). GENERATED from AF_PLACE_DATA in index.html — edit there.
 *
 * Direct web access is blocked by .htaccess (it prints nothing anyway).
 */

declare(strict_types=1);

const AF_CMB_NAME = 'Bandaranaike International Airport (CMB)';

/** [name, lat, lon, [aliases]] */
const AF_PLACES = [
    ['Bandaranaike International Airport (CMB)', 7.1808, 79.8841, ['katunayake airport', 'colombo airport', 'cmb', 'bia']],
    ['Ratmalana Airport (RML)', 6.8219, 79.8873, ['ratmalana', 'rml']],
    ['Mattala Rajapaksa International Airport (HRI)', 6.2854, 81.1218, ['mattala', 'hri']],
    ['Jaffna International Airport (JAF)', 9.7926, 80.069, ['palaly', 'jaf']],
    ['Colombo Fort Railway Station', 6.934, 79.8504, ['fort station']],
    ['Kandy Railway Station', 7.2898, 80.6327, []],
    ['Peradeniya Junction Railway Station', 7.2587, 80.596, []],
    ['Hatton Railway Station', 6.8916, 80.5955, []],
    ['Nanu Oya Railway Station', 6.942, 80.7438, ['nuwara eliya station']],
    ['Haputale Railway Station', 6.7682, 80.9576, []],
    ['Ella Railway Station', 6.8761, 81.0475, []],
    ['Badulla Railway Station', 6.9801, 81.0597, []],
    ['Galle Railway Station', 6.0335, 80.2142, []],
    ['Matara Railway Station', 5.9486, 80.5425, []],
    ['Anuradhapura Railway Station', 8.3465, 80.4111, []],
    ['Jaffna Railway Station', 9.6654, 80.0212, []],
    ['Colombo', 6.9389, 79.8542, ['colombo city']],
    ['Colombo Fort', 6.9337, 79.8501, ['fort']],
    ['Kollupitiya', 6.9135, 79.8508, ['colpetty', 'colombo 3']],
    ['Bambalapitiya', 6.8954, 79.855, ['colombo 4']],
    ['Wellawatte', 6.8771, 79.8603, ['colombo 6']],
    ['Borella', 6.9148, 79.8776, ['colombo 8']],
    ['Rajagiriya', 6.9095, 79.8962, []],
    ['Battaramulla', 6.9022, 79.9196, []],
    ['Sri Jayawardenepura Kotte', 6.8868, 79.9187, ['kotte']],
    ['Nugegoda', 6.87, 79.8883, []],
    ['Maharagama', 6.8473, 79.9266, []],
    ['Dehiwala', 6.8513, 79.866, []],
    ['Mount Lavinia', 6.8317, 79.8626, ['mt lavinia']],
    ['Moratuwa', 6.7747, 79.8826, []],
    ['Piliyandala', 6.8, 79.9231, []],
    ['Homagama', 6.8412, 80.0034, []],
    ['Malabe', 6.9041, 79.9546, []],
    ['Kaduwela', 6.9357, 79.9843, []],
    ['Avissawella', 6.9521, 80.2129, []],
    ['Padukka', 6.8415, 80.0916, []],
    ['Negombo', 7.2094, 79.8331, []],
    ['Katunayake', 7.1568, 79.8717, []],
    ['Seeduwa', 7.1306, 79.8782, []],
    ['Ja-Ela', 7.0794, 79.8908, ['jaela']],
    ['Kandana', 7.0495, 79.8982, []],
    ['Wattala', 6.9899, 79.8927, []],
    ['Kelaniya', 6.9611, 79.8944, []],
    ['Kiribathgoda', 6.9782, 79.927, []],
    ['Kadawatha', 7.0021, 79.9512, []],
    ['Ragama', 7.0263, 79.9236, []],
    ['Gampaha', 7.0929, 79.9952, []],
    ['Minuwangoda', 7.1693, 79.9485, []],
    ['Divulapitiya', 7.2248, 80.0199, []],
    ['Nittambuwa', 7.1441, 80.0965, []],
    ['Veyangoda', 7.1534, 80.0602, []],
    ['Mirigama', 7.2475, 80.1299, []],
    ['Panadura', 6.7133, 79.9026, []],
    ['Wadduwa', 6.6656, 79.9582, []],
    ['Kalutara', 6.5853, 79.9633, []],
    ['Beruwala', 6.4788, 79.9828, []],
    ['Aluthgama', 6.4342, 80.0031, []],
    ['Horana', 6.7159, 80.0626, []],
    ['Matugama', 6.5222, 80.1137, []],
    ['Bandaragama', 6.6655, 79.9729, []],
    ['Bentota', 6.4215, 79.9979, []],
    ['Induruwa', 6.374, 80.0106, []],
    ['Kosgoda', 6.3386, 80.0289, []],
    ['Ahungalla', 6.3138, 80.0351, []],
    ['Ambalangoda', 6.2389, 80.0541, []],
    ['Hikkaduwa', 6.1408, 80.1028, []],
    ['Galle', 6.0328, 80.215, []],
    ['Unawatuna', 6.0202, 80.2475, []],
    ['Habaraduwa', 5.9946, 80.3085, []],
    ['Koggala', 5.9904, 80.3226, []],
    ['Ahangama', 5.9735, 80.3623, []],
    ['Elpitiya', 6.2919, 80.1619, []],
    ['Baddegama', 6.1725, 80.1779, []],
    ['Weligama', 5.9751, 80.4291, []],
    ['Mirissa', 5.9494, 80.4558, []],
    ['Polhena', 5.9395, 80.5242, []],
    ['Matara', 5.9478, 80.5483, []],
    ['Dikwella', 5.9646, 80.6947, []],
    ['Akuressa', 6.1012, 80.4765, []],
    ['Kamburupitiya', 6.0803, 80.5674, []],
    ['Tangalle', 6.0251, 80.7949, ['tangalla']],
    ['Beliatta', 6.0649, 80.7367, []],
    ['Ambalantota', 6.1225, 81.0239, []],
    ['Hambantota', 6.1249, 81.1243, []],
    ['Tissamaharama', 6.2771, 81.2893, ['tissa']],
    ['Kirinda', 6.2901, 81.3925, []],
    ['Weeraketiya', 6.1484, 80.7632, []],
    ['Kandy', 7.2931, 80.635, ['maha nuwara']],
    ['Peradeniya', 7.2642, 80.5931, []],
    ['Katugastota', 7.3221, 80.625, []],
    ['Akurana', 7.3603, 80.6006, []],
    ['Digana', 7.2965, 80.7334, []],
    ['Kundasale', 7.2805, 80.6845, []],
    ['Teldeniya', 7.3096, 80.7649, []],
    ['Pilimathalawa', 7.2667, 80.5333, []],
    ['Kadugannawa', 7.2573, 80.5195, []],
    ['Gampola', 7.1636, 80.5703, []],
    ['Nawalapitiya', 7.0559, 80.535, []],
    ['Matale', 7.472, 80.6234, []],
    ['Dambulla', 7.8742, 80.6511, []],
    ['Sigiriya', 7.9498, 80.7463, []],
    ['Galewela', 7.7597, 80.5711, []],
    ['Naula', 7.707, 80.6531, []],
    ['Rattota', 7.5206, 80.6776, []],
    ['Ukuwela', 7.4213, 80.6332, []],
    ['Nuwara Eliya', 6.974, 80.767, []],
    ['Hatton', 6.8916, 80.5985, []],
    ['Talawakelle', 6.9419, 80.6584, []],
    ['Nanu Oya', 6.941, 80.7442, []],
    ['Kotagala', 6.9253, 80.6091, []],
    ['Maskeliya', 6.8339, 80.572, []],
    ['Dalhousie (Nallathanniya)', 6.8204, 80.5176, ['nallathanniya']],
    ['Bogawantalawa', 6.7962, 80.6781, []],
    ['Ginigathena', 6.9864, 80.4889, []],
    ['Ragala', 7.0118, 80.8573, []],
    ['Badulla', 6.99, 81.057, []],
    ['Ella', 6.8736, 81.049, []],
    ['Bandarawela', 6.8305, 80.9888, []],
    ['Haputale', 6.7682, 80.9602, []],
    ['Diyatalawa', 6.807, 80.9572, []],
    ['Welimada', 6.9058, 80.9098, []],
    ['Demodara', 7.2278, 81.1691, []],
    ['Passara', 6.9354, 81.157, []],
    ['Mahiyanganaya', 7.3397, 80.9939, []],
    ['Monaragala', 6.8727, 81.3506, ['moneragala']],
    ['Wellawaya', 6.7318, 81.1018, []],
    ['Kataragama', 6.4136, 81.3324, []],
    ['Buttala', 6.762, 81.2465, []],
    ['Bibile', 7.1601, 81.2254, []],
    ['Siyambalanduwa', 6.9064, 81.5592, []],
    ['Ratnapura', 6.6804, 80.4023, []],
    ['Kuruwita', 6.7761, 80.3669, []],
    ['Eheliyagoda', 6.8531, 80.2625, []],
    ['Pelmadulla', 6.6234, 80.5431, []],
    ['Balangoda', 6.6501, 80.6986, []],
    ['Embilipitiya', 6.3341, 80.8538, []],
    ['Udawalawe', 6.4242, 80.8173, ['uda walawe']],
    ['Kalawana', 6.5311, 80.3965, []],
    ['Rakwana', 6.475, 80.6116, []],
    ['Kegalle', 7.2532, 80.3454, []],
    ['Mawanella', 7.2523, 80.446, []],
    ['Rambukkana', 7.3239, 80.3957, []],
    ['Pinnawala', 7.3004, 80.3863, []],
    ['Warakapola', 7.225, 80.1965, []],
    ['Ruwanwella', 7.0459, 80.2538, []],
    ['Yatiyantota', 7.029, 80.295, []],
    ['Kitulgala', 6.9923, 80.4188, []],
    ['Dehiowita', 6.9666, 80.2659, []],
    ['Kurunegala', 7.487, 80.3649, []],
    ['Kuliyapitiya', 7.4701, 80.044, []],
    ['Pannala', 7.3284, 80.0241, []],
    ['Narammala', 7.4307, 80.2143, []],
    ['Alawwa', 7.2934, 80.2386, []],
    ['Polgahawela', 7.3353, 80.3002, []],
    ['Mawathagama', 7.433, 80.4464, []],
    ['Wariyapola', 7.6198, 80.2461, []],
    ['Hettipola', 7.6056, 80.0788, []],
    ['Giriulla', 7.3299, 80.1225, []],
    ['Dambadeniya', 7.3682, 80.1494, []],
    ['Nikaweratiya', 7.7504, 80.1157, []],
    ['Ibbagamuwa', 7.5479, 80.4496, []],
    ['Melsiripura', 7.6428, 80.5093, []],
    ['Galgamuwa', 7.9957, 80.2706, []],
    ['Maho', 7.8239, 80.2736, []],
    ['Puttalam', 8.0316, 79.83, []],
    ['Chilaw', 7.5763, 79.7954, []],
    ['Wennappuwa', 7.3426, 79.8413, []],
    ['Marawila', 7.413, 79.831, []],
    ['Dankotuwa', 7.2975, 79.8822, []],
    ['Nattandiya', 7.4122, 79.8643, []],
    ['Madampe', 7.4954, 79.8414, []],
    ['Kalpitiya', 8.2368, 79.7662, []],
    ['Anamaduwa', 7.8776, 80.0109, []],
    ['Anuradhapura', 8.335, 80.4106, []],
    ['Mihintale', 8.3583, 80.5122, []],
    ['Kekirawa', 8.0408, 80.5971, []],
    ['Habarana', 8.0423, 80.7565, []],
    ['Tambuttegama', 8.1611, 80.3003, []],
    ['Eppawala', 8.1461, 80.4114, []],
    ['Medawachchiya', 8.5388, 80.493, []],
    ['Polonnaruwa', 7.9395, 81.0003, []],
    ['Kaduruwela', 7.9306, 81.0327, []],
    ['Hingurakgoda', 8.0415, 80.9532, []],
    ['Minneriya', 8.0396, 80.9064, []],
    ['Giritale', 8.0003, 80.9244, []],
    ['Medirigiriya', 8.1423, 80.9713, []],
    ['Trincomalee', 8.5764, 81.2345, ['trinco']],
    ['Uppuveli', 8.6013, 81.2178, []],
    ['Nilaveli', 8.6815, 81.1916, []],
    ['Kuchchaveli', 8.8183, 81.0996, []],
    ['Kinniya', 8.5016, 81.1912, []],
    ['Kantale', 8.367, 81.0028, []],
    ['Batticaloa', 7.7245, 81.6987, []],
    ['Pasikudah', 7.9224, 81.5651, ['passikudah']],
    ['Kalkudah', 7.9157, 81.5604, []],
    ['Valaichchenai', 7.9091, 81.5331, []],
    ['Eravur', 7.7757, 81.6048, []],
    ['Kattankudy', 7.6805, 81.729, []],
    ['Ampara', 7.2978, 81.679, []],
    ['Arugam Bay', 6.8469, 81.8307, ['pottuvil point']],
    ['Pottuvil', 6.8732, 81.83, []],
    ['Kalmunai', 7.4132, 81.8269, []],
    ['Akkaraipattu', 7.2143, 81.8482, []],
    ['Panama', 6.7557, 81.8049, []],
    ['Jaffna', 9.6651, 80.0093, ['yalpanam']],
    ['Nallur', 9.6735, 80.029, []],
    ['Point Pedro', 9.8241, 80.2362, []],
    ['Chavakachcheri', 9.6571, 80.1585, []],
    ['Kankesanthurai', 9.8237, 80.0337, ['kks']],
    ['Velvettithurai', 9.8167, 80.1667, []],
    ['Karainagar', 9.7404, 79.885, []],
    ['Kilinochchi', 9.384, 80.4087, []],
    ['Paranthan', 9.4401, 80.4071, []],
    ['Mannar', 8.9813, 79.9044, []],
    ['Talaimannar', 9.1055, 79.7307, []],
    ['Vavuniya', 8.7594, 80.5001, []],
    ['Mullaitivu', 9.2699, 80.8145, []],
    ['World Trade Center Colombo', 6.9335, 79.8431, ['wtc', 'world trade centre']],
    ['Galle Face Green', 6.925, 79.8445, []],
    ['Gangaramaya Temple', 6.9166, 79.8567, []],
    ['Lotus Tower', 6.927, 79.8583, ['nelum kuluna']],
    ['Port City Colombo', 6.9322, 79.8408, []],
    ['Kelaniya Raja Maha Vihara', 6.9515, 79.9187, ['kelaniya temple']],
    ['Sigiriya Rock Fortress', 7.957, 80.7603, ['lion rock']],
    ['Pidurangala Rock', 7.9661, 80.7617, []],
    ['Dambulla Cave Temple', 7.8566, 80.6485, ['golden temple dambulla']],
    ['Knuckles Mountain Range', 7.45, 80.8, ['knuckles']],
    ['Temple of the Sacred Tooth Relic', 7.2936, 80.6413, ['dalada maligawa', 'temple of the tooth']],
    ['Royal Botanical Gardens, Peradeniya', 7.2686, 80.5965, ['peradeniya gardens']],
    ['Adam\'s Peak (Sri Pada)', 6.8262, 80.5232, ['adams peak', 'sri pada']],
    ['Horton Plains National Park', 6.8067, 80.8021, ['worlds end']],
    ['Seetha Amman Kovil', 6.9332, 80.8106, ['seetha eliya']],
    ['Gregory Lake', 6.9561, 80.7795, []],
    ['Nine Arches Bridge', 6.876, 81.0616, ['nine arch bridge']],
    ['Little Adam\'s Peak', 6.8651, 81.063, ['little adams peak']],
    ['Ella Rock', 6.855, 81.0524, []],
    ['Ravana Falls', 6.8412, 81.0551, []],
    ['Lipton\'s Seat', 6.7807, 81.0155, ['liptons seat']],
    ['Galle Fort', 6.0266, 80.2177, ['dutch fort']],
    ['Pinnawala Elephant Orphanage', 7.3005, 80.3872, []],
    ['Sinharaja Forest Reserve', 6.4011, 80.5026, ['sinharaja']],
    ['Udawalawe National Park', 6.4389, 80.8886, []],
    ['Yala National Park', 6.3486, 81.4505, ['yala safari']],
    ['Bundala National Park', 6.2099, 81.2252, []],
    ['Minneriya National Park', 8.0161, 80.8501, []],
    ['Polonnaruwa Ancient City', 7.9465, 81.0007, []],
    ['Ruwanwelisaya', 8.35, 80.3964, []],
    ['Wilpattu National Park', 8.3071, 80.1488, []],
    ['Ethagala (Elephant Rock)', 7.4883, 80.365, ['kurunegala rock']],
    ['Koneswaram Temple', 8.5812, 81.2457, []],
    ['Pigeon Island National Park', 8.7193, 81.2054, []],
    ['Nallur Kandaswamy Kovil', 9.6747, 80.0294, ['nallur temple']],
    ['Gal Oya National Park', 7.2333, 81.4667, []],
    ['Cinnamon Grand Colombo', 6.9181, 79.8485, []],
    ['Cinnamon Lakeside Colombo', 6.9292, 79.8493, []],
    ['Shangri-La Colombo', 6.9285, 79.8443, ['shangrila']],
    ['Hilton Colombo', 6.9207, 79.8563, []],
    ['The Kingsbury Colombo', 6.9328, 79.8419, ['kingsbury']],
    ['Galle Face Hotel', 6.9201, 79.8461, []],
    ['Taj Samudra Colombo', 6.9264, 79.8456, []],
    ['Mount Lavinia Hotel', 6.8334, 79.8621, []],
    ['Jetwing Blue', 7.2422, 79.8419, ['negombo']],
    ['Heritance Negombo', 7.2349, 79.8409, []],
    ['Cinnamon Bentota Beach', 6.421, 79.996, []],
    ['Heritance Ahungalla', 6.3116, 80.0319, []],
    ['Jetwing Lighthouse', 6.0522, 80.1813, []],
    ['Amangalla', 6.0286, 80.217, []],
    ['Shangri-La Hambantota', 6.1123, 81.0635, []],
    ['Jetwing Yala', 6.2509, 81.3946, []],
    ['Cinnamon Wild Yala', 6.2596, 81.4046, []],
    ['Earl\'s Regency Kandy', 7.2823, 80.6698, ['earls regency']],
    ['Amaya Hills Kandy', 7.2547, 80.602, []],
    ['Heritance Kandalama', 7.8713, 80.7077, ['kandalama']],
    ['Jetwing Vil Uyana', 7.951, 80.7443, []],
    ['Cinnamon Lodge Habarana', 8.0349, 80.7492, []],
    ['The Grand Hotel Nuwara Eliya', 6.9684, 80.7647, []],
    ['Heritance Tea Factory', 6.9925, 80.8287, []],
    ['98 Acres Resort Ella', 6.8709, 81.0627, ['98 acres']],
    ['Trinco Blu by Cinnamon', 8.6181, 81.2179, []],
    ['Jetwing Jaffna', 9.6647, 80.0134, []],
];

const AF_DISTRICT_NAMES = ['Colombo', 'Gampaha', 'Kalutara', 'Kandy', 'Matale', 'Nuwara Eliya', 'Galle', 'Matara', 'Hambantota', 'Jaffna', 'Kilinochchi', 'Mannar', 'Vavuniya', 'Mullaitivu', 'Batticaloa', 'Ampara', 'Trincomalee', 'Kurunegala', 'Puttalam', 'Anuradhapura', 'Polonnaruwa', 'Badulla', 'Monaragala', 'Ratnapura', 'Kegalle'];

/** Same folding as afFold() in index.html: lower case, no accents, words only. */
function af_fold(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) {
            $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
        }
    }
    $s = preg_replace("/[\u{2019}'`]/u", '', $s) ?? $s;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
}

/**
 * Coordinates of the known place named in $text, or null. The match nearest
 * the end wins; a trailing ", X District" is set aside first.
 * @return array{lat: float, lon: float, label: string}|null
 */
function af_place_coords(string $text): ?array
{
    static $keys = null, $districts = null;
    if ($keys === null) {
        $keys = [];
        foreach (AF_PLACES as [$name, $lat, $lon, $aliases]) {
            $keys[] = [af_fold($name), $name, $lat, $lon];
            foreach ($aliases as $alias) {
                $a = af_fold($alias);
                if (strlen($a) >= 3 && $a !== 'fort') {
                    $keys[] = [$a, $name, $lat, $lon];
                }
            }
        }
        $districts = array_map('af_fold', AF_DISTRICT_NAMES);
    }
    $text  = (string) preg_replace('/^Current Location:\s*/i', '', $text);
    $parts = array_values(array_filter(array_map('af_fold', explode(',', $text)), 'strlen'));
    // The page's own values end in ", <name> District": set that aside.
    while (count($parts) > 1 && preg_match('/^(.+) district$/', $parts[count($parts) - 1], $dm) === 1
        && in_array($dm[1], $districts, true)) {
        array_pop($parts);
    }
    $t = ' ' . implode(' ', $parts) . ' ';
    if (strlen(trim($t)) < 2) {
        return null;
    }
    $best = null;
    foreach ($keys as [$k, $name, $lat, $lon]) {
        $at = strrpos($t, ' ' . $k . ' ');
        if ($at === false) {
            continue;
        }
        $end = $at + strlen($k);
        if ($best === null || $end > $best[0] || ($end === $best[0] && strlen($k) > $best[1])) {
            $best = [$end, strlen($k), $name, $lat, $lon];
        }
    }
    return $best === null ? null : ['lat' => (float) $best[3], 'lon' => (float) $best[4], 'label' => $best[2]];
}

function af_haversine_km(array $a, array $b): float
{
    $rad  = M_PI / 180;
    $dLat = ($b['lat'] - $a['lat']) * $rad;
    $dLon = ($b['lon'] - $a['lon']) * $rad;
    $s = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLon / 2) ** 2;
    return 2 * 6371 * asin(min(1.0, sqrt($s)));
}

function af_fmt_km(float $km): string
{
    return '~' . ($km < 10 ? number_format($km, 1, '.', '') : (string) round($km)) . ' km';
}

function af_fmt_time(float $mins): string
{
    $m  = (int) max(5, round($mins / 5) * 5);
    $hh = intdiv($m, 60);
    $mm = $m % 60;
    return $hh ? $hh . 'h' . ($mm ? ' ' . $mm . 'm' : '') : $mm . ' min';
}

/**
 * A straight-line x 1.3 road estimate along pick-up, stops, drop-off (an empty
 * pick-up is CMB, as on the page). Unknown stops are skipped; null when either
 * end cannot be placed.
 * @param string[] $stops
 * @return array{km: float, text: string, legs: string}|null
 */
function af_route_estimate(string $pickup, array $stops, string $dropoff): ?array
{
    $names = array_merge([$pickup !== '' ? $pickup : AF_CMB_NAME], $stops, [$dropoff]);
    $pts = [];
    foreach ($names as $i => $name) {
        $p = trim($name) === '' ? null : af_place_coords($name);
        if ($p === null) {
            if ($i === 0 || $i === count($names) - 1) {
                return null;
            }
            continue;
        }
        if ($pts && af_haversine_km($pts[count($pts) - 1], $p) < 0.3) {
            continue;
        }
        $pts[] = $p;
    }
    if (count($pts) < 2) {
        return null;
    }
    $km = 0.0;
    $legs = [];
    for ($i = 1; $i < count($pts); $i++) {
        $leg = af_haversine_km($pts[$i - 1], $pts[$i]) * 1.3;
        $km += $leg;
        $legs[] = $pts[$i - 1]['label'] . ' → ' . $pts[$i]['label'] . ' ' . af_fmt_km($leg);
    }
    $mins  = $km / 45 * 60;
    $multi = count($pts) > 2;
    $text  = $multi
        ? af_fmt_km($km) . ' total (approx. ' . af_fmt_time($mins) . ' driving) estimate, ' . count($legs) . ' legs: '
            . implode(' → ', array_column($pts, 'label'))
        : af_fmt_km($km) . ' (approx. ' . af_fmt_time($mins) . ') estimate, ' . $pts[0]['label'] . ' to ' . $pts[1]['label'];
    return ['km' => round($km, 1), 'text' => $text, 'legs' => $multi ? implode('; ', $legs) : ''];
}
