<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user for the application (password is prompted securely)',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Admin username')
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $email = (string) $input->getArgument('email');

        $repo = $this->entityManager->getRepository(User::class);
        if ($repo->findOneBy(['username' => $username]) || $repo->findOneBy(['email' => $email])) {
            $io->error('A user with this username or email already exists.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Password (min. 8 characters)', function (?string $value) {
            if (null === $value || strlen($value) < 8) {
                throw new \RuntimeException('The password must be at least 8 characters long.');
            }

            return $value;
        });

        $admin = new User();
        $admin->setUsername($username);
        $admin->setNom('Administrateur');
        $admin->setEmail($email);
        $admin->setRole('ROLE_ADMIN');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $io->success(sprintf('Admin user "%s" created.', $username));

        return Command::SUCCESS;
    }
}
