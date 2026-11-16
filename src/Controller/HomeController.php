<?php

namespace App\Controller;

use App\Entity\Voiture;
use App\Repository\VoitureRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /** Daily-rate ceilings offered by the search bar's "Price" field, in USD. */
    public const PRICE_STEPS = [500, 1000, 2500, 5000, 10000];

    #[Route('/', name: 'app_home')]
    public function index(VoitureRepository $voitureRepository): Response
    {
        return $this->render('home/index.html.twig', [
            // The featured section shows up to 6 real, currently available
            // vehicles - never a fixed, possibly-stale list.
            'featuredVoitures' => $voitureRepository->findBy(['disponible' => true], ['prixParJour' => 'DESC'], 6),
            'brandCounts' => $voitureRepository->countAvailableByBrand(),
            'brands' => $voitureRepository->findDistinctBrands(),
            'priceSteps' => self::PRICE_STEPS,
        ]);
    }

    #[Route('/voitures', name: 'app_catalog')]
    public function catalog(Request $request, VoitureRepository $voitureRepository): Response
    {
        $brand = $request->query->get('brand') ?: null;
        $q = trim((string) $request->query->get('q', '')) ?: null;
        $sort = $request->query->get('sort', 'price_asc');
        // Daily-rate ceiling from the "Price" field; only the listed steps
        // are accepted, anything else means "any price".
        $maxPrice = (int) $request->query->get('maxPrice', 0);
        if (!in_array($maxPrice, self::PRICE_STEPS, true)) {
            $maxPrice = null;
        }
        if (!in_array($sort, ['price_asc', 'price_desc', 'name'], true)) {
            $sort = 'price_asc';
        }

        // The search bar's pick-up/return dates are not tied to a filter
        // the database can actually express (Voiture has no per-date
        // availability, only a single disponible flag - see
        // VehicleAvailabilityTest's documentation of that gap). They are
        // only validated here, so an invalid range surfaces as a clear
        // message instead of being silently ignored, and the valid dates
        // are carried through to pre-fill the reservation form once a
        // vehicle is chosen.
        // The homepage search sends a pick-up date on its own; anything that
        // is not a real Y-m-d date is dropped before it reaches a template
        // or a booking link.
        $validDate = static fn (mixed $v): ?string => is_string($v) && false !== \DateTime::createFromFormat('!Y-m-d', $v) ? $v : null;
        $pickupDate = $validDate($request->query->get('pickupDate'));
        $returnDate = $validDate($request->query->get('returnDate'));
        if ($pickupDate && $returnDate) {
            try {
                $debut = new \DateTime($pickupDate);
                $fin = new \DateTime($returnDate);
                if ($fin <= $debut) {
                    $this->addFlash('error', 'The return date must be after the pick-up date.');
                    $pickupDate = $returnDate = null;
                }
            } catch (\Exception) {
                $this->addFlash('error', 'Please enter valid pick-up and return dates.');
                $pickupDate = $returnDate = null;
            }
        }

        return $this->render('home/catalog.html.twig', [
            'voitures' => $voitureRepository->searchAvailable($brand, $q, $sort, null !== $maxPrice ? (float) $maxPrice : null),
            'brands' => $voitureRepository->findDistinctBrands(),
            'currentBrand' => $brand,
            'currentQuery' => $q,
            'currentSort' => $sort,
            'currentMaxPrice' => $maxPrice,
            'priceSteps' => self::PRICE_STEPS,
            'pickupDate' => $pickupDate,
            'returnDate' => $returnDate,
        ]);
    }

    /**
     * Public vehicle detail page. Any vehicle may be viewed this way (not
     * only the available ones listed on /voitures), so a shared link never
     * silently 404s just because the car has since become unavailable -
     * the page states its availability clearly instead.
     */
    #[Route('/voitures/{id}', name: 'app_catalog_show')]
    public function show(Voiture $voiture, Request $request): Response
    {
        return $this->render('home/show.html.twig', [
            'voiture' => $voiture,
            'pickupDate' => $request->query->get('pickupDate'),
            'returnDate' => $request->query->get('returnDate'),
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('home/contact.html.twig');
    }

    /**
     * Attribution for the Creative Commons photographs used on the site
     * (the data lives in config/preview/fleet.json - see the
     * photo_credits() Twig function).
     */
    #[Route('/credits', name: 'app_credits')]
    public function credits(): Response
    {
        return $this->render('home/credits.html.twig');
    }
}
