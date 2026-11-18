<?php

namespace App\Command;

use App\Entity\Reservation;
use App\Entity\Voiture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fills var/preview.db with the synthetic preview fleet described in
 * config/preview/fleet.json, and puts its photos into public/uploads/voitures/
 * (git-ignored): Wikimedia Commons photos are downloaded, photos supplied by
 * the site owner ("localFile") are copied from config/preview/photos/.
 *
 * Safety: refuses to run unless the active Doctrine connection resolves to
 * a file named preview.db, so it can never write to var/data.db. It is
 * additive and idempotent - vehicles are matched by brand + model and
 * updated or created. The only deletions are vehicles listed under
 * "retired", and only when they have no booking (otherwise they are just
 * marked unavailable), so users and bookings survive a re-run.
 */
#[AsCommand(
    name: 'app:preview:seed',
    description: 'Fill var/preview.db with the synthetic preview fleet (never touches var/data.db)',
)]
final class SeedPreviewCommand extends Command
{
    private const USER_AGENT = 'CarRentPreview/1.0 (preview fleet seeder)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-download', null, InputOption::VALUE_NONE, 'Do not download missing photos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $params = $this->entityManager->getConnection()->getParams();
        $path = str_replace('\\', '/', (string) ($params['path'] ?? ''));
        if ('preview.db' !== basename($path)) {
            $io->error(sprintf(
                'Refusing to run: the database connection resolves to "%s", not var/preview.db. '
                .'Set DATABASE_URL="sqlite:///%%kernel.project_dir%%/var/preview.db" (for example in .env.local) first.',
                '' !== $path ? $path : 'a non-SQLite database',
            ));

            return Command::FAILURE;
        }

        $manifest = json_decode(
            (string) file_get_contents($this->projectDir.'/config/preview/fleet.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Creates the tables on a brand-new preview.db and adds anything
        // missing on an existing one. Never drops a table or column.
        (new SchemaTool($this->entityManager))->updateSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );

        $this->copyLocalPhotos($manifest['photos'], $io);
        if (!$input->getOption('skip-download')) {
            $this->downloadPhotos(array_filter($manifest['photos'], static fn (array $p): bool => isset($p['commonsFile'])), $io);
        }

        $repository = $this->entityManager->getRepository(Voiture::class);
        $created = $updated = 0;
        foreach ($manifest['vehicles'] as $row) {
            $voiture = $repository->findOneBy(['marque' => $row['marque'], 'modele' => $row['modele']]);
            if (null === $voiture) {
                $voiture = (new Voiture())
                    ->setMarque($row['marque'])
                    ->setModele($row['modele'])
                    // Only set on creation: a booking flips this flag, and a
                    // re-run must not silently make a booked car available.
                    ->setDisponible($row['disponible']);
                $this->entityManager->persist($voiture);
                ++$created;
            } else {
                ++$updated;
            }
            $voiture->setPrixParJour((float) $row['prixParJour'])->setImage($row['image']);
        }
        $retired = 0;
        foreach ($manifest['retired'] ?? [] as $row) {
            $voiture = $repository->findOneBy(['marque' => $row['marque'], 'modele' => $row['modele']]);
            if (null === $voiture) {
                continue;
            }
            $bookings = $this->entityManager->getRepository(Reservation::class)->count(['voiture' => $voiture]);
            if (0 === $bookings) {
                $this->entityManager->remove($voiture);
            } else {
                $voiture->setDisponible(false);
            }
            ++$retired;
        }
        $this->entityManager->flush();

        $io->success(sprintf('Preview fleet ready in %s: %d created, %d updated, %d retired.', $path, $created, $updated, $retired));

        return Command::SUCCESS;
    }

    /**
     * Copies photos supplied by the site owner into the uploads directory,
     * refreshing any copy that differs from the source.
     *
     * @param array<string, array<string, string>> $photos
     */
    private function copyLocalPhotos(array $photos, SymfonyStyle $io): void
    {
        foreach ($photos as $filename => $photo) {
            if (!isset($photo['localFile'])) {
                continue;
            }
            $source = $this->projectDir.'/'.$photo['localFile'];
            $target = $this->projectDir.'/public/uploads/voitures/'.$filename;
            if (!is_file($source)) {
                $io->warning(sprintf('Missing %s; skipped.', $photo['localFile']));
                continue;
            }
            if (is_file($target) && md5_file($target) === md5_file($source)) {
                $io->writeln(sprintf(' <comment>skip</comment>  %s (up to date)', $filename));
                continue;
            }
            copy($source, $target);
            $io->writeln(sprintf(' <info>copied</info> %s', $filename));
        }
    }

    /**
     * @param array<string, array{commonsFile: string}> $photos
     */
    private function downloadPhotos(array $photos, SymfonyStyle $io): void
    {
        $dir = $this->projectDir.'/public/uploads/voitures';
        foreach ($photos as $filename => $photo) {
            $target = $dir.'/'.$filename;
            if (is_file($target) && filesize($target) > 0) {
                $io->writeln(sprintf(' <comment>skip</comment>  %s (already present)', $filename));
                continue;
            }

            $info = $this->httpClient->request('GET', 'https://commons.wikimedia.org/w/api.php', [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'query' => [
                    'action' => 'query', 'format' => 'json', 'prop' => 'imageinfo',
                    'iiprop' => 'url', 'iiurlwidth' => 1600, 'titles' => $photo['commonsFile'],
                ],
            ])->toArray();
            $page = array_values($info['query']['pages'] ?? [])[0] ?? [];
            $url = $page['imageinfo'][0]['thumburl'] ?? null;
            if (null === $url) {
                $io->warning(sprintf('Could not resolve %s on Wikimedia Commons; skipped.', $photo['commonsFile']));
                continue;
            }

            $response = $this->httpClient->request('GET', $url, ['headers' => ['User-Agent' => self::USER_AGENT]]);
            $type = $response->getHeaders()['content-type'][0] ?? '';
            if (!str_starts_with($type, 'image/jpeg')) {
                $io->warning(sprintf('Unexpected content type "%s" for %s; skipped.', $type, $filename));
                continue;
            }
            file_put_contents($target, $response->getContent());
            $io->writeln(sprintf(' <info>saved</info> %s (%d KB)', $filename, (int) (filesize($target) / 1024)));
        }
    }
}
